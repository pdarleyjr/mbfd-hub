<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\OperationalAlerts\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalAlert;
use App\Models\Station;
use App\Models\User;
use App\Services\Fleet\ApparatusOperationalAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** R 44 at Station 4 on truck 17503, E 1 at Station 1 on truck 20503, and a reserve rescue on truck 1033. */
trait BuildsOperationalAlertFixtures
{
    protected Station $station1;

    protected Station $station4;

    protected Apparatus $r44;

    protected Apparatus $e1;

    protected Apparatus $spareRescue;

    protected function setUpAlertFixtures(): void
    {
        config()->set('google_sheets.apparatus_sync_enabled', false);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->station1 = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Test Way', 'is_active' => true]);
        $this->station4 = Station::query()->create(['station_number' => 4, 'name' => 'Station 4', 'address' => '4 Test Way', 'is_active' => true]);
        $this->r44 = $this->truck($this->station4, 'R 44', 'r44', '17503', 'Rescue', 'In Service');
        $this->e1 = $this->truck($this->station1, 'E 1', 'e1', '20503', 'Engine', 'In Service');
        $this->spareRescue = $this->truck($this->station4, 'R-1033', 'r1033', '1033', 'Rescue', 'Reserve');
    }

    protected function truck(Station $station, string $designation, string $slug, string $number, string $type, string $status): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => str_replace(' ', '', $designation), 'designation' => $designation,
            'slug' => $slug, 'name' => "{$type} {$number}", 'type' => $type, 'vehicle_number' => $number,
            'status' => $status, 'vin' => 'VIN-'.$number, 'assignment' => $station->name,
            'daily_checkout_requirement' => $status === 'Reserve' ? 'reserve' : 'required',
        ]);
    }

    protected function alerts(): ApparatusOperationalAlertService
    {
        return app(ApparatusOperationalAlertService::class);
    }

    /** @param array<string, mixed> $overrides */
    protected function alert(?Apparatus $unit = null, array $overrides = []): ApparatusOperationalAlert
    {
        return $this->alerts()->create([
            'apparatus_id' => ($unit ?? $this->r44)->id,
            'category' => ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP,
            'title' => 'Upcoming Apparatus Changeout',
            'message' => 'This unit is scheduled to change vehicles.',
            ...$overrides,
        ], null);
    }

    /**
     * A non-swap alert on R 44: these may stack on one unit, where a planned changeout may not.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function notice(array $overrides = []): ApparatusOperationalAlert
    {
        return $this->alert($this->r44, ['category' => ApparatusOperationalAlert::CATEGORY_GENERAL, ...$overrides]);
    }

    protected function fleetManager(): User
    {
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        $manager->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $manager->fresh();
    }

    /** @param array<string, mixed> $overrides */
    protected function changeout(ApparatusOperationalAlert $alert, array $overrides = []): ApparatusChangeout
    {
        return ApparatusChangeout::query()->create([
            'client_submission_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'alert_id' => $alert->id,
            'unit_slug' => $alert->unit_slug,
            'unit_designation' => $alert->unit_designation,
            'station_id' => $alert->station_id,
            'from_apparatus_id' => $alert->apparatus_id,
            'to_apparatus_id' => $this->spareRescue->id,
            'from_vehicle_number' => $alert->vehicle_number,
            'to_vehicle_number' => (string) $this->spareRescue->vehicle_number,
            'status' => ApparatusChangeout::STATUS_COMPLETED,
            'handoff_status' => ApparatusChangeout::HANDOFF_COMPLETE,
            'submitted_by_name' => 'Test Member',
            ...$overrides,
        ]);
    }

    /** The unit identity moves to the spare truck; the alert stays on the truck it was raised on. */
    protected function moveR44ToSpare(): void
    {
        DB::table('apparatuses')->where('id', $this->spareRescue->id)->update(['slug' => 'moving']);
        DB::table('apparatuses')->where('id', $this->r44->id)->update(['slug' => 'r17503', 'designation' => 'R-17503', 'unit_id' => 'R-17503']);
        DB::table('apparatuses')->where('id', $this->spareRescue->id)->update(['slug' => 'r44', 'designation' => 'R 44', 'unit_id' => 'R44']);
    }
}
