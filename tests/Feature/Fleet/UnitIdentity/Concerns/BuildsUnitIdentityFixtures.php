<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\UnitIdentity\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\Station;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The Nov 2 shape: E1 (front-line) on truck 20503, and spare truck 002-18 listed as E11. The incident on Nov 5 typed each
 * truck's vehicle number into the other's unit ID.
 */
trait BuildsUnitIdentityFixtures
{
    protected Station $station1;

    protected Apparatus $e1;

    protected Apparatus $spare;

    protected function buildUnitIdentityFixtures(): void
    {
        config()->set('google_sheets.apparatus_sync_enabled', false);
        // Only E1 exists here, so only E1 is front-line: the other approved units would otherwise show as missing.
        config()->set('daily_checkout_frontline', [['unit_id' => 'E1', 'station' => 1, 'template' => 'engine']]);
        $this->station1 = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1051 Jefferson Ave', 'is_active' => true]);
        $this->e1 = Apparatus::query()->create([
            'station_id' => $this->station1->id, 'assignment' => 'Station 1', 'current_location' => 'Station 1',
            'unit_id' => 'E1', 'designation' => 'E 1', 'name' => 'ENGINE E1', 'slug' => 'e1', 'type' => 'Engine',
            'vehicle_number' => '20503', 'vin' => 'TESTVIN000020503', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
            'current_engine_hours' => 4100.5, 'current_miles' => 61000,
        ]);
        $this->spare = Apparatus::query()->create([
            'station_id' => $this->station1->id, 'assignment' => 'Reserve', 'current_location' => 'Fire Fleet',
            'unit_id' => 'E11', 'designation' => 'E 11', 'name' => 'ENGINE E11', 'slug' => 'e11', 'type' => 'Engine',
            'vehicle_number' => '002-18', 'vin' => 'TESTVIN000000218', 'status' => 'Available',
            'daily_checkout_requirement' => 'reserve', 'daily_checkout_template' => 'pending',
            'current_engine_hours' => 2200, 'current_miles' => 30500,
        ]);
    }

    protected function superAdmin(): User
    {
        $admin = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Fleet Admin']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $admin->fresh();
    }
}
