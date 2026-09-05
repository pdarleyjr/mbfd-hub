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
        if (filter_var($cityEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The authoritative city email is invalid.');
        }

        DB::transaction(function () use ($employee, $user, $cityEmail): void {
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
