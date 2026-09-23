<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Security\SecurityAuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class MemberOnboardingInvitationService
{
    public function __construct(
        private readonly CloudflareEmailDispatcher $email,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    /** @return array{status:string,user_id:int|null,employee_profile_id:int|null} */
    public function assess(Employee $employee): array
    {
        $users = User::query()->where('employee_profile_id', $employee->id)->limit(2)->get();
        if ($users->count() !== 1) {
            return $this->result('identity_conflict');
        }
        $user = $users->sole();
        if (! $this->hasAuthoritativeCityEmail($employee)) {
            return $this->result('missing_authoritative_city_email', $user, $employee);
        }
        if (! $this->hasApprovedRosterBinding($employee)) {
            return $this->result('unapproved_roster_binding', $user, $employee);
        }
        if (Employee::query()->whereKeyNot($employee->id)->where('employee_id', $employee->employee_id)->exists()) {
            return $this->result('duplicate_employee_id', $user, $employee);
        }
        if (! $this->eligible($user, $employee)) {
            return $this->result('not_pending_onboarding', $user, $employee);
        }
        if ($this->hasEmailCollision($user, $employee)) {
            return $this->result('email_conflict', $user, $employee);
        }
        if ($this->hasCurrentInvitation($user, $employee, CarbonImmutable::now())) {
            return $this->result('already_invited', $user, $employee);
        }

        return $this->result('ready', $user, $employee);
    }

    /**
     * Issue a replacement invitation for one preflighted canonical account.
     * The bearer token is transmitted only to the authoritative roster address
     * and is never returned, logged, or persisted in plaintext.
     */
    public function issue(User $user, CarbonImmutable $at, ?User $initiator = null): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $invitation = DB::transaction(function () use ($user, $at, $tokenHash, $initiator): ?MemberOnboardingInvitation {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $employee = Employee::query()->lockForUpdate()->find($current->employee_profile_id);
            if (! $employee instanceof Employee || ! $this->eligible($current, $employee) || $this->hasEmailCollision($current, $employee)) {
                throw new InvalidArgumentException('The canonical onboarding identity is no longer eligible.');
            }
            $values = [
                'employee_profile_id' => $employee->id,
                'email' => $this->authoritativeCityEmail($employee),
                'security_version' => $current->security_version,
                'token_hash' => $tokenHash,
                'expires_at' => $at->addMinutes(30),
                'sent_at' => null,
                'redeemed_at' => null,
                'redeemed_binding_hash' => null,
                'consumed_at' => null,
                'delivery_status' => 'pending',
            ];
            $invitation = MemberOnboardingInvitation::query()->where('user_id', $current->id)->lockForUpdate()->first();
            if ($invitation instanceof MemberOnboardingInvitation && $this->invitationIsCurrent($invitation, $current, $employee, $at)) {
                return null;
            }
            if ($invitation instanceof MemberOnboardingInvitation) {
                $invitation->forceFill($values)->save();
            } else {
                $invitation = MemberOnboardingInvitation::query()->create(['user_id' => $current->id] + $values);
            }
            $this->audit->record($initiator ?? $current, $current, 'member_onboarding_invitation_issued', 'allowed', null, [
                'employee_profile_id' => $employee->id,
            ]);

            return $invitation;
        }, 3);
        if ($invitation === null) {
            return 'already_invited';
        }

        try {
            $delivery = $this->email->send(
                to: [$invitation->email],
                subject: 'Set up your MBFD Hub account',
                text: "Use this private MBFD Hub link to create your password. It expires in 30 minutes and can be used once.\n\n".url('/member-onboarding/invite#'.$token)."\n\nIf you did not expect this email, ignore it and contact MBFD Hub support.",
                html: null,
                sourceType: 'member_onboarding_invitation',
                sourceId: (string) $invitation->id,
                actor: $initiator ?? $user,
            );
            $failed = in_array($delivery->status, ['failed', 'blocked', 'accepted_with_delivery_issues'], true);
            $values = ['delivery_status' => $failed ? 'failed' : 'queued', 'sent_at' => $at];
            if ($failed) {
                $values += ['token_hash' => null, 'expires_at' => null];
            }
        } catch (Throwable) {
            $values = ['delivery_status' => 'failed', 'token_hash' => null, 'expires_at' => null];
        }
        MemberOnboardingInvitation::query()->whereKey($invitation->id)
            ->where('token_hash', $tokenHash)
            ->where('delivery_status', 'pending')
            ->update($values);

        return $invitation->fresh()->delivery_status;
    }

    /** @return array{invitation_id:int,user:User}|null */
    public function redeem(string $token, string $binding, CarbonImmutable $at): ?array
    {
        if (! $this->validToken($token) || $binding === '') {
            return null;
        }
        $tokenHash = hash('sha256', $token);

        return DB::transaction(function () use ($tokenHash, $binding, $at): ?array {
            $invitation = MemberOnboardingInvitation::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();
            if (! $invitation instanceof MemberOnboardingInvitation) {
                return null;
            }
            $user = User::query()->with('employeeProfile')->lockForUpdate()->find($invitation->user_id);
            $employee = Employee::query()->lockForUpdate()->find($invitation->employee_profile_id);
            if (! $user instanceof User || ! $employee instanceof Employee || ! $this->current($user, $employee, $invitation, $at)) {
                return null;
            }
            $invitation->forceFill([
                'redeemed_at' => $at,
                'redeemed_binding_hash' => hash('sha256', $binding),
                'delivery_status' => 'redeemed',
            ])->save();
            $this->audit->record($user, $user, 'member_onboarding_invitation_redeemed', 'allowed', null, [
                'employee_profile_id' => $employee->id,
            ]);

            return ['invitation_id' => $invitation->id, 'user' => $user];
        }, 3);
    }

    private function current(User $user, Employee $employee, MemberOnboardingInvitation $invitation, CarbonImmutable $at): bool
    {
        return $this->eligible($user, $employee)
            && $invitation->employee_profile_id === $employee->id
            && $invitation->security_version === $user->security_version
            && $invitation->token_hash !== null
            && $invitation->expires_at?->greaterThan($at) === true
            && $invitation->redeemed_at === null
            && $invitation->consumed_at === null
            && ! $this->hasEmailCollision($user, $employee)
            && hash_equals($this->authoritativeCityEmail($employee), $invitation->email);
    }

    private function eligible(User $user, Employee $employee): bool
    {
        return $user->employee_profile_id === $employee->id
            && $user->employee_id === $employee->employee_id
            && $user->getRawOriginal('account_status') === AccountStatus::PendingActivation->value
            && $user->bootstrap_onboarding_eligible
            && $user->bootstrap_onboarding_completed_at === null
            && $employee->roster_status === 'active'
            && $this->hasAuthoritativeCityEmail($employee)
            && $this->hasApprovedRosterBinding($employee)
            && ! Employee::query()->whereKeyNot($employee->id)->where('employee_id', $employee->employee_id)->exists();
    }

    private function hasApprovedRosterBinding(Employee $employee): bool
    {
        return MemberOnboardingRosterBinding::query()
            ->where('employee_profile_id', $employee->id)
            ->where('employee_id', $employee->employee_id)
            ->where('city_email', $this->authoritativeCityEmail($employee))
            ->exists();
    }

    private function hasCurrentInvitation(User $user, Employee $employee, CarbonImmutable $at): bool
    {
        $invitation = MemberOnboardingInvitation::query()->where('user_id', $user->id)->first();

        return $invitation instanceof MemberOnboardingInvitation && $this->invitationIsCurrent($invitation, $user, $employee, $at);
    }

    private function invitationIsCurrent(MemberOnboardingInvitation $invitation, User $user, Employee $employee, CarbonImmutable $at): bool
    {
        return in_array($invitation->delivery_status, ['pending', 'queued', 'redeemed'], true)
            && $invitation->user_id === $user->id
            && $invitation->employee_profile_id === $employee->id
            && $invitation->security_version === $user->security_version
            && hash_equals($this->authoritativeCityEmail($employee), $invitation->email)
            && $invitation->token_hash !== null
            && $invitation->expires_at?->greaterThan($at) === true;
    }

    private function hasEmailCollision(User $user, Employee $employee): bool
    {
        $email = $this->authoritativeCityEmail($employee);

        return Employee::query()->whereKeyNot($employee->id)->whereRaw('LOWER(city_email) = ?', [$email])->exists()
            || User::query()->whereKeyNot($user->id)->whereRaw('LOWER(email) = ?', [$email])->exists();
    }

    private function hasAuthoritativeCityEmail(Employee $employee): bool
    {
        $email = strtolower(trim((string) $employee->city_email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && str_ends_with($email, '@miamibeachfl.gov');
    }

    private function authoritativeCityEmail(Employee $employee): string
    {
        return strtolower(trim((string) $employee->city_email));
    }

    /** @return array{status:string,user_id:int|null,employee_profile_id:int|null} */
    private function result(string $status, ?User $user = null, ?Employee $employee = null): array
    {
        return ['status' => $status, 'user_id' => $user?->id, 'employee_profile_id' => $employee?->id];
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }
}
