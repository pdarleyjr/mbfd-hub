<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;

final class CanonicalUserResolver
{
    public function byIdentifier(string $identifier): ?User
    {
        $employeeId = trim($identifier);
        $matches = User::query()
            ->whereHas('employeeProfile', static function ($query) use ($employeeId): void {
                $query->where('employee_id', $employeeId);
            })
            ->with('employeeProfile')
            ->limit(2)
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $email = mb_strtolower($employeeId);

        $matches = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->with('employeeProfile')
            ->limit(2)
            ->get();

        return $matches->count() === 1 && $matches->first()?->employee_profile_id !== null
            ? $matches->first()
            : null;
    }
}
