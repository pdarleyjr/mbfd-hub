<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\CityEmailVerification;
use App\Models\Employee;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class CityEmailVerificationService
{
    public function __construct(
        private readonly CloudflareEmailDispatcher $email,
        private readonly CanonicalCityEmailService $canonicalEmail,
    ) {}

    public function candidate(User $user): string
    {
        $user = $user->fresh('employeeProfile');
        foreach ([$user?->employeeProfile?->city_email, $user?->email] as $email) {
            if (is_string($email) && $this->validCityEmail(strtolower(trim($email)))) {
                return strtolower(trim($email));
            }
        }
        $parts = preg_split('/\s+/', trim((string) ($user->employeeProfile->name ?? $user->name ?? '')));
        if ($parts === false || count($parts) < 2) {
            return '';
        }
        $local = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($parts[0].$parts[count($parts) - 1])));

        return $local !== '' ? $local.'@miamibeachfl.gov' : '';
    }

    public function requiresReview(User $user): bool
    {
        if ($user->fresh()?->employee_profile_id === null) {
            return false;
        }

        return $this->connectedEmail($user) === null && $this->status($user) === null;
    }

    /**
     * Existing account addresses are grandfathered, not newly mailbox-verified.
     * This local syntax/reserved-domain policy never performs a DNS lookup.
     */
    public function connectedEmail(User $user): ?string
    {
        $current = $user->fresh('employeeProfile');
        foreach ([$current?->employeeProfile?->city_email, $current?->email] as $value) {
            if (! is_string($value)) {
                continue;
            }
            $email = strtolower(trim($value));
            if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $domain = substr($email, (int) strrpos($email, '@') + 1);
            if (preg_match('/\.[a-z][a-z0-9-]{1,62}\z/', $domain) !== 1
                || preg_match('/(?:\A|\.)example\.(com|org|net)\z/', $domain) === 1
                || preg_match('/\.(invalid|test|example|localhost)\z/', $domain) === 1) {
                continue;
            }

            return $email;
        }

        return null;
    }

    public function status(User $user): ?CityEmailVerification
    {
        $current = $user->fresh('employeeProfile');
        if ($current === null || ! $current->employeeProfile instanceof Employee) {
            return null;
        }
        $row = CityEmailVerification::query()->where('user_id', $current->getKey())->first();

        return $row !== null && $this->bindingIsCurrent($current, $current->employeeProfile, $row) ? $row : null;
    }

    public function acknowledge(User $user, string $email): CityEmailVerification
    {
        $email = strtolower(trim($email));
        if (! $this->validCityEmail($email)) {
            throw new InvalidArgumentException('Enter your @miamibeachfl.gov email address.');
        }

        return DB::transaction(function () use ($user, $email): CityEmailVerification {
            [$current, $employee] = $this->lockedIdentity($user);
            $this->assertAvailable($current, $employee, $email);
            $row = CityEmailVerification::query()->where('user_id', $current->getKey())->lockForUpdate()->first();
            if ($row !== null && $this->bindingIsCurrent($current, $employee, $row) && $row->email === $email) {
                return $row;
            }

            return CityEmailVerification::query()->updateOrCreate(['user_id' => $current->getKey()], [
                'employee_profile_id' => $employee->getKey(),
                'email' => $email,
                'original_user_email' => $current->email,
                'original_employee_city_email' => $employee->city_email,
                'security_version' => $current->security_version,
                'acknowledged_at' => now(),
                'token_hash' => null,
                'token_expires_at' => null,
                'sent_at' => null,
                'delivery_status' => 'pending',
                'verified_at' => null,
            ]);
        });
    }

    public function issue(User $user): CityEmailVerification
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $row = DB::transaction(function () use ($user, $hash): CityEmailVerification {
            [$current, $employee] = $this->lockedIdentity($user);
            $row = CityEmailVerification::query()->where('user_id', $current->getKey())->lockForUpdate()->first();
            if ($row === null || ! $this->bindingIsCurrent($current, $employee, $row)) {
                throw new InvalidArgumentException('Confirm your current city email before requesting a verification message.');
            }
            if ($row->verified_at !== null) {
                return $row;
            }
            $row->forceFill(['token_hash' => $hash, 'token_expires_at' => now()->addMinutes(30), 'sent_at' => null, 'delivery_status' => 'pending'])->save();

            return $row;
        });
        if ($row->verified_at !== null) {
            return $row;
        }

        try {
            $link = url('/account/city-email/verify/'.$token);
            $delivery = $this->email->send(
                to: [$row->email],
                subject: 'Confirm your MBFD Hub city email',
                text: "Sign in to your MBFD Hub account and confirm this email address using the link below. The link expires in 30 minutes.\n\n{$link}\n\nIf you did not request this message, you can ignore it.",
                html: null,
                sourceType: 'city_email_verification',
                sourceId: (string) $row->getKey(),
                actor: $user,
            );
            $failed = in_array($delivery->status, ['failed', 'blocked', 'accepted_with_delivery_issues'], true);
            $values = ['delivery_status' => $failed ? 'failed' : 'queued', 'sent_at' => now()];
            if ($failed) {
                $values += ['token_hash' => null, 'token_expires_at' => null];
            }
        } catch (Throwable) {
            $values = ['delivery_status' => 'failed', 'token_hash' => null, 'token_expires_at' => null];
        }
        CityEmailVerification::query()->whereKey($row->getKey())->where('token_hash', $hash)->update($values);

        return $row->refresh();
    }

    public function inspectToken(User $user, string $token): ?CityEmailVerification
    {
        $row = $this->status($user);

        return $row !== null && $this->tokenMatches($row, $token) ? $row : null;
    }

    public function verify(User $user, string $token): bool
    {
        $oldVerifiedEmail = null;
        try {
            $verified = DB::transaction(function () use ($user, $token, &$oldVerifiedEmail): bool {
                [$current, $employee] = $this->lockedIdentity($user);
                $row = CityEmailVerification::query()->where('user_id', $current->getKey())->lockForUpdate()->first();
                if ($row === null || ! $this->bindingIsCurrent($current, $employee, $row) || ! $this->tokenMatches($row, $token)) {
                    return false;
                }
                $this->assertAvailable($current, $employee, $row->email);
                if ($current->email_verified_at !== null && $current->email !== $row->email && $this->validCityEmail($current->email)) {
                    $oldVerifiedEmail = $current->email;
                }
                $this->canonicalEmail->sync($employee, $current, $row->email);
                $current->refresh()->forceFill(['email_verified_at' => now()])->save();
                $row->forceFill([
                    'verified_at' => now(), 'delivery_status' => 'verified',
                    'original_user_email' => $row->email, 'original_employee_city_email' => $row->email,
                    'token_hash' => null, 'token_expires_at' => null,
                ])->save();

                return true;
            });
        } catch (InvalidArgumentException) {
            return false;
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23505', '23000'], true)) {
                return false;
            }
            throw $exception;
        }
        if ($verified && $oldVerifiedEmail !== null) {
            try {
                $this->email->send([$oldVerifiedEmail], 'Your MBFD Hub city email changed', 'Your MBFD Hub city email was changed after mailbox verification. If you did not make this change, contact your MBFD Hub administrator.', null, 'city_email_changed', (string) $user->getKey(), $user);
            } catch (Throwable) {
                Log::notice('city_email_change_notice_failed', ['user_id' => $user->getKey()]);
            }
        }

        return $verified;
    }

    /** @return array{User, Employee} */
    private function lockedIdentity(User $user): array
    {
        $current = User::query()->lockForUpdate()->findOrFail($user->getKey());
        $employee = Employee::query()->whereKey($current->employee_profile_id)->lockForUpdate()->first();
        if (! $current->isAuthenticationAllowed() || $employee === null || $current->employee_id !== $employee->employee_id) {
            throw new InvalidArgumentException('A current linked MBFD account is required.');
        }

        return [$current, $employee];
    }

    private function bindingIsCurrent(User $user, Employee $employee, CityEmailVerification $row): bool
    {
        return $user->isAuthenticationAllowed()
            && $user->employee_id === $employee->employee_id
            && $row->employee_profile_id === $employee->getKey()
            && ($row->verified_at !== null || $row->security_version === $user->security_version)
            && $row->original_user_email === $user->email
            && $row->original_employee_city_email === $employee->city_email
            && ($row->verified_at === null || ($user->email_verified_at !== null && $row->email === $user->email && $row->email === $employee->city_email));
    }

    private function tokenMatches(CityEmailVerification $row, string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            && $row->verified_at === null
            && is_string($row->token_hash)
            && $row->token_expires_at !== null
            && $row->token_expires_at->isFuture()
            && hash_equals($row->token_hash, hash('sha256', $token));
    }

    private function validCityEmail(string $email): bool
    {
        return strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && str_ends_with($email, '@miamibeachfl.gov');
    }

    private function assertAvailable(User $user, Employee $employee, string $email): void
    {
        if (User::query()->whereKeyNot($user->getKey())->whereRaw('LOWER(email) = ?', [$email])->exists()
            || Employee::query()->whereKeyNot($employee->getKey())->whereRaw('LOWER(city_email) = ?', [$email])->exists()) {
            throw new InvalidArgumentException('This city email is already associated with another member. Contact your administrator.');
        }
    }
}
