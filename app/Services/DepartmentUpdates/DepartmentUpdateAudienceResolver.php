<?php

declare(strict_types=1);

namespace App\Services\DepartmentUpdates;

use App\Enums\AccountStatus;
use App\Enums\DepartmentUpdateAudience;
use App\Models\DepartmentUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DepartmentUpdateAudienceResolver
{
    /** @var list<string> */
    private const OFFICER_RANKS = [
        'Fire Chief',
        'Deputy Fire Chief',
        'Division Chief',
        'Captain',
        'Lieutenant',
    ];

    /** @return Collection<int, User> */
    public function resolve(DepartmentUpdate $update): Collection
    {
        $query = User::query()
            ->where('account_status', AccountStatus::Active->value);

        match ($update->audience) {
            DepartmentUpdateAudience::Officers => $this->ranks($query, self::OFFICER_RANKS),
            DepartmentUpdateAudience::DriverEngineers,
            DepartmentUpdateAudience::Selected => $query->whereKey($update->audience_user_ids ?? []),
            DepartmentUpdateAudience::Firefighters => $this->ranks($query, ['Firefighter']),
            DepartmentUpdateAudience::Administration => $query->where(function (Builder $query): void {
                $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', 'super_admin'))
                    ->orWhereHas('permissions', fn (Builder $permissions): Builder => $permissions
                        ->where('name', 'admin.access')
                        ->where('guard_name', 'web'));
            }),
            DepartmentUpdateAudience::Everyone => null,
        };

        return $query->orderBy('id')->get();
    }

    /** @param list<string> $ranks */
    private function ranks(Builder $query, array $ranks): void
    {
        $query->whereHas('employeeProfile', fn (Builder $employees): Builder => $employees->whereIn('rank', $ranks));
    }
}
