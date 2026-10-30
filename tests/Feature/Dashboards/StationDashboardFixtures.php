<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Enums\AccountStatus;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Shared accounts, stations and query counting for the station dashboard adapter suites. */
trait StationDashboardFixtures
{
    private int $fixtureSeq = 0;

    /** @param  list<string>  $permissions */
    protected function adminWith(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'name' => 'Admin '.++$this->fixtureSeq,
            'must_change_password' => false,
        ]);
        foreach (['admin.access', ...$permissions] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function station(int $number): Station
    {
        return Station::query()->firstOrCreate(['station_number' => $number], [
            'name' => 'Station '.$number,
            'address' => $number.' Test Street',
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function stationRequest(array $attributes = []): StationRequest
    {
        return StationRequest::query()->create(array_merge([
            'station_id' => $this->station(1)->id,
            'requester_name_snapshot' => 'Pat Requester',
            'request_type' => 'repair_service',
            'subject_type' => 'kitchen',
            'title' => 'Kitchen sink leak',
            'description' => 'The sink drips constantly.',
            'priority' => 'normal',
            'status' => 'pending',
        ], $attributes))->refresh();
    }

    protected function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
