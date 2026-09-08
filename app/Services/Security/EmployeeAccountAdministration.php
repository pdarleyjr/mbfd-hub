<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\CityEmailVerification;
use App\Models\Employee;
use App\Models\EmployeeProfileEvent;
use App\Models\User;
use App\Services\Identity\AccountSecurityService as IdentityAccountSecurityService;
use App\Services\Identity\CanonicalCityEmailService;
use App\Services\Identity\CanonicalUserProvisioner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class EmployeeAccountAdministration
{
    public function createForEmployee(User $actor, Employee $employee, string $temporaryPassword, string $currentPassword, string $reason): User
    {
        try {
            Validator::make(['temporary_password' => $temporaryPassword, 'reason' => $reason], ['temporary_password' => 'required|string|min:12|max:255', 'reason' => 'required|string|max:500'])->validate();

            return DB::transaction(function () use ($actor, $employee, $temporaryPassword, $currentPassword, $reason): User {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->authorize($actor, $currentPassword);
                $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                if ($employee->roster_status !== 'active' || $employee->user()->exists()
                    || User::query()->where('employee_id', $employee->employee_id)->exists()) {
                    throw ValidationException::withMessages(['temporary_password' => 'This employee is not active or already has a matching account. Review the existing identity; no duplicate account was created.']);
                }
                $result = app(CanonicalUserProvisioner::class)->create($employee->id, 'MISSING_OR_UNSUPPORTED', now());
                $user = $result['user'];
                app(IdentityAccountSecurityService::class)->completeCanonicalLink($user, $employee->id, $employee->employee_id, Hash::make($temporaryPassword), now());
                $user->refresh();
                $user->forceFill(['must_change_password' => true])->save();
                $user->assignRole(Role::findOrCreate('member', 'web'));
                if (filled($employee->city_email)) {
                    app(CanonicalCityEmailService::class)->sync($employee, $user, $employee->city_email);
                }
                app(SecurityAuditRecorder::class)->record($actor, $user, 'create_employee_account', 'allowed', trim($reason), ['employee_profile_id' => $employee->id]);

                return $user->fresh();
            });
        } catch (Throwable $exception) {
            // The newly created User was rolled back; retain the roster-side event.
            EmployeeProfileEvent::query()->create([
                'employee_id' => $employee->id,
                'actor_user_id' => $actor->id,
                'target_user_id' => null,
                'action' => 'create_employee_account',
                'result' => $this->failureResult($exception),
                'reason' => mb_substr(trim($reason), 0, 500),
            ]);
            throw $exception;
        }
    }

    public function approveNonemployee(User $actor, User $target, string $currentPassword, string $reason): void
    {
        try {
            Validator::make(['reason' => $reason], ['reason' => 'required|string|max:500'])->validate();
            DB::transaction(function () use ($actor, $target, $currentPassword, $reason): void {
                $users = User::query()->whereKey([$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $actor = $users->get($actor->id);
                $target = $users->get($target->id);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                $this->authorize($actor, $currentPassword);
                abort_if($actor->is($target) || $target->employee_profile_id !== null, 403);
                $target->forceFill(['account_classification' => 'approved_nonemployee'])->save();
                app(SecurityAuditRecorder::class)->record($actor, $target, 'approve_nonemployee', 'allowed', trim($reason));
            });
        } catch (Throwable $exception) {
            $this->auditFailure($actor, $target, 'approve_nonemployee', $reason, $exception);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function updateUnlinkedProfile(User $actor, User $target, array $data): User
    {
        try {
            abort_if(array_diff(array_keys($data), ['name', 'display_name', 'phone']) !== [], 403);

            return DB::transaction(function () use ($actor, $target, $data): User {
                $users = User::query()->whereKey([$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $actor = $users->get($actor->id);
                $target = $users->get($target->id);
                abort_unless($actor instanceof User && $target instanceof User && $actor->isAuthenticationAllowed(), 403);
                abort_if($target->employee_profile_id !== null, 409);
                abort_unless($actor->is($target) || $actor->can('admin.members.manage'), 403);
                abort_if($target->hasRole('super_admin') && ! $actor->hasRole('super_admin'), 403);
                abort_if($actor->is($target) && array_key_exists('name', $data), 403);
                $validated = Validator::make($data, ['name' => 'sometimes|required|string|max:255', 'display_name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:255'])->validate();
                $target->fill($validated)->save();
                app(SecurityAuditRecorder::class)->record($actor, $target, 'update_account_profile', 'allowed', null, ['fields' => array_keys($validated)]);

                return $target;
            });
        } catch (Throwable $exception) {
            $this->auditFailure($actor, $target, 'update_account_profile', null, $exception);
            throw $exception;
        }
    }

    public function changeUnlinkedRecoveryEmail(User $actor, User $target, string $email, string $currentPassword, string $reason): User
    {
        try {
            return DB::transaction(function () use ($actor, $target, $email, $currentPassword, $reason): User {
                $users = User::query()->whereKey([$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $actor = $users->get($actor->id);
                $target = $users->get($target->id);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                $this->authorize($actor, $currentPassword);
                abort_if($actor->is($target), 403);
                if ($target->employee_profile_id !== null) {
                    throw ValidationException::withMessages(['email' => 'Linked employees must use the protected city email action.']);
                }
                if (! in_array($target->getRawOriginal('account_classification'), ['unresolved', 'approved_nonemployee'], true)) {
                    throw ValidationException::withMessages(['email' => 'Review this account classification before changing its recovery identity.']);
                }
                $email = strtolower(trim($email));
                Validator::make(['email' => $email, 'reason' => $reason], [
                    'email' => 'required|string|email:rfc|max:254', 'reason' => 'required|string|max:500',
                ])->validate();
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
                    || User::query()->whereKeyNot($target->id)->whereRaw('LOWER(email) = ?', [$email])->exists()
                    || Employee::query()->whereRaw('LOWER(city_email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'Use a valid email that is not assigned to another account or employee.']);
                }
                $changed = $target->email !== $email;
                if ($changed) {
                    $broker = Password::broker();
                    if (! $broker instanceof PasswordBroker) {
                        throw new \LogicException('The configured password broker does not support token persistence.');
                    }
                    $broker->deleteToken($target);
                    try {
                        $target->forceFill(['email' => $email, 'email_verified_at' => null])->save();
                    } catch (UniqueConstraintViolationException) {
                        throw ValidationException::withMessages(['email' => 'This email was assigned to another account. Reload before trying again.']);
                    }
                    CityEmailVerification::query()->where('user_id', $target->id)->update([
                        'verified_at' => null, 'token_hash' => null, 'token_expires_at' => null, 'delivery_status' => 'failed',
                    ]);
                    $target = app(IdentityAccountSecurityService::class)->revokeAll($target, 'recovery email changed', now());
                }
                app(SecurityAuditRecorder::class)->record($actor, $target, 'change_recovery_email', 'allowed', trim($reason), ['email_changed' => $changed]);

                return $target;
            });
        } catch (Throwable $exception) {
            $this->auditFailure($actor, $target, 'change_recovery_email', $reason, $exception);
            throw $exception;
        }
    }

    private function authorize(User $actor, string $password): void
    {
        abort_unless($actor->isAuthenticationAllowed() && $actor->hasRole('super_admin'), 403);
        if (! Hash::check($password, $actor->getAuthPassword())) {
            throw new CurrentPasswordMismatch;
        }
    }

    private function auditFailure(User $actor, User $target, string $action, ?string $reason, Throwable $exception): void
    {
        app(SecurityAuditRecorder::class)->record($actor, $target, $action, $this->failureResult($exception), $reason === null ? null : mb_substr(trim($reason), 0, 500));
    }

    private function failureResult(Throwable $exception): string
    {
        return $exception instanceof AuthorizationException
            || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403)
            ? 'denied' : 'failed';
    }
}
