<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CanonicalCityEmailService
{
    public function sync(Employee $employee, User $user, string $cityEmail): void
    {
        $cityEmail = strtolower(trim($cityEmail));
        if (strlen($cityEmail) > 254 || filter_var($cityEmail, FILTER_VALIDATE_EMAIL) === false
            || ! str_ends_with($cityEmail, '@miamibeachfl.gov')) {
            throw new InvalidArgumentException('The authoritative city email is invalid.');
        }

        DB::transaction(function () use ($employee, $user, $cityEmail): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            if ($user->employee_profile_id !== $employee->getKey()
                || $user->employee_id !== $employee->employee_id) {
                throw new InvalidArgumentException('The canonical User and Employee do not match.');
            }

            $employeeCollision = Employee::query()
                ->whereRaw('LOWER(city_email) = ?', [$cityEmail])
                ->whereKeyNot($employee->getKey())
                ->exists();
            $userCollision = User::query()
                ->whereRaw('LOWER(email) = ?', [$cityEmail])
                ->whereKeyNot($user->getKey())
                ->exists();
            if ($employeeCollision || $userCollision) {
                throw new InvalidArgumentException('Canonical city email collision.');
            }

            $employee->forceFill(['city_email' => $cityEmail])->save();
            $user->forceFill(['email' => $cityEmail])->save();
        });
    }
}
