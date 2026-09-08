<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\Security\AccountSecurityAction;
use App\Exceptions\CurrentPasswordMismatch;
use App\Models\Employee;
use App\Models\EmployeeProfileEvent;
use App\Models\User;
use App\Policies\AccountSecurityPolicy;
use App\Services\Identity\AccountSecurityService as IdentitySecurity;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EmployeeIdentityService
{
    public function __construct(
        private readonly LastCriticalAdministratorGuard $criticalAdministrators,
        private readonly IdentitySecurity $identitySecurity,
        private readonly SecurityAuditRecorder $audit,
        private readonly AccountSecurityPolicy $securityPolicy,
    ) {}

    public function correctEmployeeId(User $actor, Employee $employee, string $newEmployeeId, string $currentPassword, string $reason): Employee
    {
        $target = $employee->user()->first();
        $this->mutate($actor, $employee, $target, 'correct_employee_id', $currentPassword, $reason,
            function (User $currentActor, Employee $currentEmployee, ?User $currentTarget) use ($newEmployeeId): array {
                $newEmployeeId = trim($newEmployeeId);
                if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,19}\z/D', $newEmployeeId) !== 1) {
                    throw ValidationException::withMessages(['new_employee_id' => 'Enter an Employee ID of 1 to 20 letters, numbers, dots, underscores, or hyphens.']);
                }
                if (Employee::query()->where('employee_id', $newEmployeeId)->whereKeyNot($currentEmployee->id)->exists()
                    || User::query()->where('employee_id', $newEmployeeId)->when($currentTarget !== null, fn ($query) => $query->whereKeyNot($currentTarget->id))->exists()) {
                    throw ValidationException::withMessages(['new_employee_id' => 'That Employee ID belongs to another identity.']);
                }
                $before = $currentEmployee->employee_id;
                $currentEmployee->forceFill(['employee_id' => $newEmployeeId])->save();
                if ($currentTarget !== null) {
                    $this->identitySecurity->completeCanonicalLink($currentTarget, $currentEmployee->id, $newEmployeeId, null, now(), activatePending: false);
                }

                return ['before_employee_id' => $before, 'after_employee_id' => $newEmployeeId];
            });

        return $employee->refresh();
    }

    public function link(User $actor, User $target, Employee $employee, string $currentPassword, string $reason): User
    {
        $this->mutate($actor, $employee, $target, 'link_employee', $currentPassword, $reason,
            function (User $currentActor, Employee $currentEmployee, ?User $currentTarget): array {
                if ($currentTarget === null || $currentTarget->employee_profile_id !== null || $currentEmployee->roster_status === 'departed'
                    || ($currentTarget->employee_id !== null && $currentTarget->employee_id !== $currentEmployee->employee_id)) {
                    throw ValidationException::withMessages(['employee_profile_id' => 'Select an active employee and an unlinked matching account. Existing links cannot be reassigned.']);
                }
                $classification = $currentTarget->getRawOriginal('account_classification');
                $this->identitySecurity->completeCanonicalLink($currentTarget, $currentEmployee->id, $currentEmployee->employee_id, null, now(), activatePending: false);
                // Profile compatibility is synchronized by the canonical transition.
                // Classification and mailbox-proof changes belong to this approved correction.
                User::query()->whereKey($currentTarget->id)->update([
                    'email_verified_at' => null,
                    'account_classification' => 'unresolved',
                ]);

                return ['employee_profile_id' => $currentEmployee->id, 'before_account_classification' => $classification, 'after_account_classification' => 'unresolved'];
            });

        return $target->refresh();
    }

    public function changeEmploymentStatus(User $actor, Employee $employee, string $status, string $currentPassword, string $reason): Employee
    {
        $target = $employee->user()->first();
        $this->mutate($actor, $employee, $target, 'change_employment_status', $currentPassword, $reason,
            function (User $currentActor, Employee $currentEmployee, ?User $currentTarget) use ($status, $reason): array {
                if (! in_array($status, ['active', 'departed'], true)) {
                    throw new AuthorizationException('Unsupported employment status.');
                }
                if ($status === 'departed' && $currentTarget !== null) {
                    if (! $this->securityPolicy->allows($currentActor, $currentTarget, AccountSecurityAction::Disable)
                        || ! $this->criticalAdministrators->allowsDisable($currentTarget)) {
                        throw new AuthorizationException('This account cannot be disabled by the employment action.');
                    }
                    $this->identitySecurity->disable($currentTarget, trim($reason), now());
                }
                $before = $currentEmployee->roster_status;
                $currentEmployee->forceFill(['roster_status' => $status])->save();

                return ['before_status' => $before, 'after_status' => $status];
            });

        return $employee->refresh();
    }

    /** @param Closure(User, Employee, ?User): array<string, mixed> $operation */
    private function mutate(User $actor, Employee $employee, ?User $target, string $action, string $currentPassword, string $reason, Closure $operation): void
    {
        try {
            DB::transaction(function () use ($actor, $employee, $target, $action, $currentPassword, $reason, $operation): void {
                $this->criticalAdministrators->lockActiveCriticalAdministrators();
                // Match profile/city-email writers: known User rows in PK order,
                // then Employee. A changed link aborts; never acquire another User
                // lock out of order after discovering a different identity.
                $ids = $target === null ? [$actor->id] : [$actor->id, $target->id];
                $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $users->get($actor->id);
                $currentTarget = $target === null ? null : $users->get($target->id);
                $currentEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                if (! $currentActor instanceof User || ! $currentActor->isAuthenticationAllowed() || ! $currentActor->hasRole('super_admin')
                    || ($target !== null && ! $currentTarget instanceof User)
                    || $currentActor->employee_profile_id === $currentEmployee->id
                    || ($currentTarget !== null && $currentActor->is($currentTarget))
                    || trim($reason) === '' || mb_strlen($reason) > 500) {
                    throw new AuthorizationException('Another identity, an active Super Administrator, and an audit reason are required.');
                }
                if (! Hash::check($currentPassword, $currentActor->getAuthPassword())) {
                    throw new CurrentPasswordMismatch('The current password is incorrect.');
                }
                $linkedId = User::query()->where('employee_profile_id', $currentEmployee->id)->value('id');
                if (($action === 'link_employee' && $linkedId !== null)
                    || ($action !== 'link_employee' && $linkedId !== $currentTarget?->id)
                    || ($action !== 'link_employee' && $currentTarget !== null && $currentTarget->employee_id !== $currentEmployee->employee_id)
                    || User::query()->where('employee_id', $currentEmployee->employee_id)->when($currentTarget !== null, fn ($query) => $query->whereKeyNot($currentTarget->id))->exists()) {
                    throw ValidationException::withMessages([$action === 'link_employee' ? 'employee_profile_id' : 'new_employee_id' => 'The identity link changed or conflicts with another account. Reload and review the exact identities.']);
                }
                $metadata = $operation($currentActor, $currentEmployee, $currentTarget);
                $this->record($currentActor, $currentEmployee, $currentTarget, $action, 'allowed', trim($reason), $metadata);
            });
        } catch (Throwable $exception) {
            $this->record($actor, $employee, $target, $action, $exception instanceof AuthorizationException || $exception instanceof ValidationException ? 'denied' : 'failed', mb_substr(trim($reason), 0, 500));
            throw $exception;
        }
    }

    /** @param array<string, mixed> $metadata */
    private function record(User $actor, Employee $employee, ?User $target, string $action, string $result, string $reason, array $metadata = []): void
    {
        EmployeeProfileEvent::query()->create([
            'actor_user_id' => $actor->id, 'employee_id' => $employee->id, 'target_user_id' => $target?->id,
            'action' => $action, 'result' => $result, 'reason' => $reason, 'metadata' => $metadata,
        ]);
        if ($target !== null) {
            $this->audit->record($actor, $target, $action, $result, $reason, $metadata);
        }
    }
}
