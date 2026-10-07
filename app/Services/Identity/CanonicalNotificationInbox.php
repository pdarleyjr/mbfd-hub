<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CanonicalNotificationInbox
{
    public function migrateEmployeeAlerts(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            // Use the established User -> Employee lock order and the stored unique FK.
            $current = User::query()->lockForUpdate()->find($user->id);
            if (! $current || $current->employee_profile_id === null
                || $current->employee_profile_id !== $user->employee_profile_id
                || $current->employee_id !== $user->employee_id) {
                return 0;
            }
            $employee = Employee::query()->whereKey($current->employee_profile_id)
                ->where('employee_id', $current->employee_id)->lockForUpdate()->first();
            if (! $employee) {
                return 0;
            }

            // Preserve IDs, read state, payload and timestamps; do not create or link identities.
            return DB::table('notifications')->where('notifiable_type', Employee::class)
                ->where('notifiable_id', $employee->id)
                ->update(['notifiable_type' => User::class, 'notifiable_id' => $current->id]);
        });
    }
}
