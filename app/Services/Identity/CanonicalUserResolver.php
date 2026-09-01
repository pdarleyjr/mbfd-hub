<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;

final class CanonicalUserResolver
{
    public function byEmployeeId(string $employeeId): ?User
    {
        $matches = User::query()
            ->whereHas('employeeProfile', static function ($query) use ($employeeId): void {
                $query->where('employee_id', $employeeId);
            })
            ->with('employeeProfile')
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
