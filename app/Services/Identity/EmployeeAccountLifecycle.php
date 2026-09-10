<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class EmployeeAccountLifecycle
{
    public function __construct(
        private CanonicalUserProvisioner $users,
        private AccountSecurityService $security,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createActive(array $attributes, CarbonInterface $at): Employee
    {
        return DB::transaction(function () use ($attributes, $at): Employee {
            $employeeId = trim((string) ($attributes['employee_id'] ?? ''));
            if ($employeeId === '') {
                throw new RuntimeException('A valid Employee ID is required.');
            }

            if (User::query()->where('employee_id', $employeeId)->lockForUpdate()->exists()) {
                throw new RuntimeException('The Employee ID conflicts with an existing canonical User.');
            }

            $employee = Employee::query()->create([
                ...$attributes,
                'employee_id' => $employeeId,
                'roster_status' => 'active',
            ]);
            $this->users->create($employee->id, 'MISSING_OR_UNSUPPORTED', $at);

            return $employee->fresh();
        }, 3);
    }

    public function ensureActive(Employee $employee, CarbonInterface $at): Employee
    {
        return DB::transaction(function () use ($employee, $at): Employee {
            $candidates = User::query()
                ->where('employee_profile_id', $employee->id)
                ->orWhere('employee_id', $employee->employee_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $current = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $linked = $candidates->filter(fn (User $user): bool => $user->employee_profile_id === $current->id);

            if ($candidates->count() > 1
                || ($candidates->count() === 1 && ($linked->count() !== 1 || $candidates->first()->employee_id !== $current->employee_id))) {
                throw new RuntimeException('The Employee identity conflicts with an existing canonical User.');
            }

            if ($current->roster_status !== 'active') {
                $current->forceFill(['roster_status' => 'active'])->save();
            }

            if ($candidates->isEmpty()) {
                $this->users->create($current->id, 'MISSING_OR_UNSUPPORTED', $at);
            }

            return $current->fresh();
        }, 3);
    }

    public function depart(Employee $employee, CarbonInterface $at): Employee
    {
        return DB::transaction(function () use ($employee, $at): Employee {
            $candidates = User::query()
                ->where('employee_profile_id', $employee->id)
                ->orWhere('employee_id', $employee->employee_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $current = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $linked = $candidates->filter(fn (User $user): bool => $user->employee_profile_id === $current->id);

            if ($candidates->count() > 1
                || ($candidates->count() === 1 && ($linked->count() !== 1 || $candidates->first()->employee_id !== $current->employee_id))) {
                throw new RuntimeException('The Employee identity conflicts with an existing canonical User.');
            }

            $user = $linked->first();
            if ($user instanceof User && $user->getRawOriginal('account_status') !== AccountStatus::Disabled->value) {
                $this->security->disable($user, 'authoritative roster departure', $at);
            }
            if ($current->roster_status !== 'departed') {
                $current->forceFill(['roster_status' => 'departed'])->save();
            }

            return $current->fresh();
        }, 3);
    }
}
