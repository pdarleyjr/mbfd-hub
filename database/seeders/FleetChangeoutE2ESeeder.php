<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\Fleet\ApparatusOperationalAlertService;
use App\Services\Security\FleetOperationsManagerAssignment;
use App\Support\HubTimezone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Fixture for the real-stack Fleet changeout browser suite (docs/apparatus-changeouts.md). Testing database only.
 *
 *   99501 Casey Member   active member with a personnel profile
 *   99502 Riley Second   a second member (stale-work and shared-phone checks)
 *   99503 Fleet Manager  Fleet Operations Manager, assigned the way production assigns the role
 *
 * Trucks follow production on 2026-10-31. Stations are created in the order 1, 4, 2 so a station's id is never its
 * number. {@see self::world()} rebuilds only the fleet rows, which is how a scenario starts from a known state.
 * One random password per run comes from the environment and is never written down.
 */
final class FleetChangeoutE2ESeeder extends Seeder
{
    public const DATABASE_FILE = 'fleet_changeout_e2e.sqlite';

    /** Tables rebuilt by world(), children before parents. */
    private const FLEET_TABLES = [
        'apparatus_changeout_items', 'apparatus_changeouts', 'apparatus_fleet_events', 'apparatus_operational_alerts',
        'apparatus_defect_events', 'apparatus_defects', 'apparatus_service_ticket_updates', 'apparatus_service_tickets',
        'apparatus_operational_status_events', 'apparatus_vehicle_swap_requests', 'apparatuses',
    ];

    public function run(): void
    {
        self::assertDisposableDatabase();
        $password = env('FLEET_CHANGEOUT_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('The browser test password must be supplied.');
        }

        $memberRole = Role::findOrCreate('member', 'web');
        foreach ([['99501', 'Casey Member'], ['99502', 'Riley Second'], ['99503', 'Fleet Manager']] as [$number, $name]) {
            $employee = Employee::query()->updateOrCreate(
                ['employee_id' => $number],
                ['name' => $name, 'rank' => $number === '99503' ? 'Captain' : 'Firefighter', 'city_email' => "fleet-changeout-{$number}@miamibeachfl.gov", 'roster_status' => 'active'],
            );
            $user = User::query()->firstOrNew(['employee_id' => $number]);
            $user->forceFill([
                'name' => $name, 'email' => $employee->city_email, 'password' => Hash::make($password), 'must_change_password' => false,
                'employee_id' => $number, 'employee_profile_id' => $employee->id, 'account_status' => AccountStatus::Active,
            ])->save();
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$memberRole]);
        }

        // The Fleet manager is assigned by a super administrator, the same service production uses.
        $super = User::query()->firstOrNew(['email' => 'fleet-changeout-super@miamibeachfl.gov']);
        $super->forceFill(['name' => 'Fixture Super', 'password' => Hash::make($password), 'must_change_password' => false, 'account_status' => AccountStatus::Active])->save();
        $super->syncRoles([Role::findOrCreate('super_admin', 'web')]);
        $manager = User::query()->where('employee_id', '99503')->firstOrFail();
        app(FleetOperationsManagerAssignment::class)->apply($super->fresh(), $manager->fresh(), true, 'browser fixture');

        self::world();
    }

    public static function assertDisposableDatabase(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== self::DATABASE_FILE) {
            throw new RuntimeException('Fleet changeout browser fixtures require their disposable testing database.');
        }
    }

    /**
     * Empties the fleet tables and rebuilds the trucks, the open ticket and defect, and the two alerts.
     *
     * @return array<string, mixed> ids the browser suite needs
     */
    public static function world(?string $r44AlertDate = null): array
    {
        self::assertDisposableDatabase();
        config()->set('google_sheets.apparatus_sync_enabled', false);

        Schema::disableForeignKeyConstraints();
        foreach (self::FLEET_TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
                DB::table('sqlite_sequence')->where('name', $table)->delete();
            }
        }
        Schema::enableForeignKeyConstraints();
        DB::table('notifications')->delete();

        $stations = [];
        foreach ([1, 4, 2] as $number) {
            $stations[$number] = Station::query()->updateOrCreate(
                ['station_number' => $number],
                ['name' => "Station {$number}", 'address' => "{$number} Fixture Way", 'is_active' => true],
            );
        }

        $r44 = self::truck($stations[4], 'R44', 'R 44', 'r44', 'Rescue 44', 'Rescue', '17503', 'In Service', 'required', 'rescue');
        $reserveRescue = self::truck($stations[2], 'R-1033', 'Reserve', 'r1033', 'RESCUE 1033', 'Rescue', '1033', 'Available', 'reserve', 'pending');
        $e1 = self::truck($stations[1], 'E1', 'E 1', 'e1', 'Engine 1', 'Engine', '20503', 'In Service', 'required', 'engine');
        $reserveEngine = self::truck($stations[2], 'E11', 'E 11', 'e11', 'ENGINE 002-18', 'Engine', '002-18', 'Available', 'reserve', 'pending');

        $ticket = ApparatusServiceTicket::query()->create([
            'ticket_number' => 'T-E2E-0001', 'apparatus_id' => $r44->id, 'station_id' => $stations[4]->id,
            'unit_designation_snapshot' => 'R 44', 'origin' => 'admin', 'category' => 'repair', 'title' => 'Exhaust repair',
            'description' => 'Exhaust leak at the manifold.', 'priority' => 'normal', 'status' => 'submitted',
        ]);
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $r44->id, 'compartment' => 'Front Cab', 'item' => 'Passenger seat', 'status' => 'open',
            'issue_type' => 'damaged', 'notes' => 'Torn seat cover.', 'reported_date' => now()->toDateString(),
        ]);

        $today = HubTimezone::today()->format('Y-m-d');
        $alerts = app(ApparatusOperationalAlertService::class);
        $r44Alert = $alerts->create([
            'apparatus_id' => $r44->id, 'category' => ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP,
            'title' => 'Upcoming Apparatus Changeout: Rescue 44',
            'message' => 'Rescue 44 moves to a reserve truck while the exhaust is repaired.',
            'reason' => 'Exhaust repair', 'scheduled_date' => $r44AlertDate ?? $today, 'scheduled_window' => 'afternoon',
            'old_truck_status_after' => 'Maintenance', 'apparatus_service_ticket_id' => $ticket->id,
            'source' => 'fleet-status-report-2026-10-30', 'dedupe_key' => 'e2e-r44',
        ], null);
        $e1Alert = $alerts->create([
            'apparatus_id' => $e1->id, 'category' => ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP,
            'title' => 'Upcoming Apparatus Changeout: Engine 1',
            'message' => 'Engine 1 changes trucks for a scheduled service.', 'reason' => 'Scheduled service',
            'scheduled_date' => $today, 'source' => 'fleet-status-report-2026-10-30', 'dedupe_key' => 'e2e-e1',
        ], null);

        return [
            'stations' => collect($stations)->map(fn (Station $station): int => $station->id)->all(),
            'trucks' => ['17503' => $r44->id, '1033' => $reserveRescue->id, '20503' => $e1->id, '002-18' => $reserveEngine->id],
            'ticket' => $ticket->id, 'defect' => $defect->id, 'r44Alert' => $r44Alert->id, 'e1Alert' => $e1Alert->id,
        ];
    }

    private static function truck(Station $station, string $unitId, string $designation, string $slug, string $name, string $type, string $number, string $status, string $requirement, string $template): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => $unitId, 'designation' => $designation, 'slug' => $slug, 'name' => $name,
            'type' => $type, 'vehicle_number' => $number, 'status' => $status, 'vin' => 'VIN-'.$number, 'assignment' => $station->name,
            'daily_checkout_requirement' => $requirement, 'daily_checkout_template' => $template,
            'current_engine_hours' => 500, 'current_miles' => 20000,
        ]);
    }
}
