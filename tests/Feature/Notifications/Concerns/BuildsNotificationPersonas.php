<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Concerns;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Members shaped like the real roster, for the notification settings and evaluator tests. */
trait BuildsNotificationPersonas
{
    protected function resetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function member(array $attributes = []): User
    {
        return User::factory()->create(['account_status' => AccountStatus::Active, ...$attributes]);
    }

    /** A member linked to a personnel record (so the Employee panel and city email apply). */
    protected function rosterMember(string $rank = 'Firefighter', ?string $cityEmail = null): User
    {
        $employee = Employee::query()->create([
            'employee_id' => 'N-'.Str::upper(Str::random(6)),
            'name' => 'Roster '.Str::random(4),
            'rank' => $rank,
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
            'city_email' => $cityEmail,
        ]);

        return $this->member(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
    }

    protected function withRole(User $user, string ...$roles): User
    {
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }

        return $user->fresh();
    }

    protected function withPermissions(User $user, string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    protected function addPushDevice(User $user, string $path = 'device'): void
    {
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$path.'-'.$user->id,
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
        ]);
    }
}
