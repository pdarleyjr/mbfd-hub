<?php

declare(strict_types=1);

/*
 * Test-only helper for the Fleet changeout browser suite (tests/e2e/fleet-changeout-real.spec.ts).
 * It boots the app against the disposable testing database and prints one JSON document.
 *
 *   php fleet-changeout-fixture.php reset [alert-date]   rebuild the fleet rows (see FleetChangeoutE2ESeeder::world)
 *   php fleet-changeout-fixture.php state                every fleet row the suite asserts on
 *   php fleet-changeout-fixture.php clear-alert <slug>   removes the planned changeout alert for a unit
 *   php fleet-changeout-fixture.php fb6                  creates Fire Boat 6 as it is in production
 *   php fleet-changeout-fixture.php fb6-state            the Fire Boat 6 rows tests/e2e/fb6-inspection-real.spec.ts asserts on
 *
 * It refuses to run anywhere except the disposable database named in FleetChangeoutE2ESeeder::DATABASE_FILE.
 */

use Database\Seeders\FleetChangeoutE2ESeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

FleetChangeoutE2ESeeder::assertDisposableDatabase();

[, $command, $argument] = $argv + [null, 'state', null];

/** @return array<string, list<array<string, mixed>>> */
function state(): array
{
    $rows = static fn (string $sql): array => array_map(static fn (object $row): array => (array) $row, DB::select($sql));

    return [
        'trucks' => $rows('select a.id, a.vehicle_number, a.unit_id, a.designation, a.name, a.slug, a.status, a.type, a.daily_checkout_requirement as requirement, a.daily_checkout_template as template, a.assignment, s.station_number from apparatuses a left join stations s on s.id = a.station_id order by a.id'),
        'tickets' => $rows('select t.id, t.ticket_number, t.status, a.vehicle_number from apparatus_service_tickets t join apparatuses a on a.id = t.apparatus_id order by t.id'),
        'defects' => $rows('select d.id, d.status, d.item, d.resolved, a.vehicle_number from apparatus_defects d join apparatuses a on a.id = d.apparatus_id order by d.id'),
        'alerts' => $rows('select id, status, category, unit_slug, vehicle_number, scheduled_date, completed_by_changeout_id from apparatus_operational_alerts order by id'),
        'changeouts' => $rows('select id, status, unit_slug, from_vehicle_number, to_vehicle_number, alert_id, return_of_changeout_id, submitted_by_name, note, client_submission_id from apparatus_changeouts order by id'),
        'items' => $rows('select i.id, i.apparatus_changeout_id as changeout_id, i.item_name, i.handling, i.custody_status, i.note from apparatus_changeout_items i order by i.id'),
        'notifications' => $rows('select id, notifiable_id, data from notifications order by created_at'),
    ];
}

switch ($command) {
    case 'reset':
        $result = FleetChangeoutE2ESeeder::world($argument);
        break;
    case 'state':
        $result = state();
        break;
    case 'clear-alert':
        DB::table('apparatus_operational_alerts')->where('unit_slug', $argument)->delete();
        $result = ['cleared' => $argument];
        break;
    case 'fb6':
        // Fire Boat 6 as it is in production: no vehicle number, no master vehicle, no status.
        $station = App\Models\Station::query()->firstOrCreate(['station_number' => 6], ['name' => 'Station 6', 'address' => '2300 Pine Tree Drive', 'is_active' => true]);
        $boat = App\Models\Apparatus::query()->firstOrCreate(['slug' => 'fb6'], [
            'station_id' => $station->id, 'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'FB6', 'type' => 'Fire Boat',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'fireboat6',
        ]);
        // Production has counted checkouts from this ledger since 2026-08-28. Without the row nothing ever counts as done.
        $snapshot = [['id' => (int) $boat->id, 'status' => 'In Service']];
        DB::table('daily_checkout_ledger_cutovers')->insertOrIgnore([
            'ledger' => App\Models\DailyCheckoutLedgerCutover::LEDGER,
            'release_sha' => str_repeat('a', 40),
            'source' => App\Models\DailyCheckoutLedgerCutover::SOURCE,
            'activated_at' => now()->subDay(),
            'apparatus_status_snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
            'snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES)),
            'apparatus_count' => 1,
        ]);
        $result = ['id' => $boat->id, 'station_id' => $boat->station_id];
        break;
    case 'fb6-issue-action':
        // Logistics acts on the boat's newest open issue through the real service, the way the admin screens do.
        $boatId = DB::table('apparatuses')->where('slug', 'fb6')->value('id');
        $defectId = (int) DB::table('apparatus_defects')->where('apparatus_id', $boatId)->where('resolved', false)->max('id');
        $actor = App\Models\User::query()->where('name', 'Fleet Manager')->first() ?? App\Models\User::query()->orderBy('id')->firstOrFail();
        $service = app(App\Services\Defects\DefectCorrectiveActionService::class);
        $argument === 'resolve'
            ? $service->resolve($defectId, $actor, ['resolution_type' => 'repair_completed', 'resolution_notes' => 'New hose fitted'])
            : $service->acknowledge($defectId, $actor);
        $result = ['defect' => $defectId, 'actor' => $actor->display_name ?: $actor->name];
        break;
    case 'fb6-state':
        $boatId = DB::table('apparatuses')->where('slug', 'fb6')->value('id');
        $rows = static fn (string $sql) => array_map(static fn (object $row): array => (array) $row, DB::select($sql, [$boatId]));
        $result = [
            'boat' => $rows('select vehicle_number, unit_master_vehicle_id from apparatuses where id = ?')[0] ?? null,
            'inspections' => $rows('select actor_user_id, vehicle_number, unit_number, processing_status, review_status, designation_at_time, inspection_reference, checklist_evidence from apparatus_inspections where apparatus_id = ? order by id'),
            'sessions' => $rows('select actor_user_id, submitted_inspection_id from daily_checkout_inspection_sessions where apparatus_id = ? order by id'),
            'changeouts' => $rows('select id from apparatus_changeouts where from_apparatus_id = ?'),
        ];
        break;
    default:
        fwrite(STDERR, "Unknown command {$command}\n");
        exit(2);
}

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
