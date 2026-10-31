<?php

declare(strict_types=1);

/*
 * Test-only helper for the Fleet changeout browser suite (tests/e2e/fleet-changeout-real.spec.ts).
 * It boots the app against the disposable testing database and prints one JSON document.
 *
 *   php fleet-changeout-fixture.php reset [alert-date]   rebuild the fleet rows (see FleetChangeoutE2ESeeder::world)
 *   php fleet-changeout-fixture.php state                every fleet row the suite asserts on
 *   php fleet-changeout-fixture.php release <number>     Fleet frees a truck: Available, open tickets completed
 *   php fleet-changeout-fixture.php approve <id>         Fleet approves a changeout through the real service
 *   php fleet-changeout-fixture.php clear-alert <slug>   removes the planned changeout alert for a unit
 *
 * It refuses to run anywhere except the disposable database named in FleetChangeoutE2ESeeder::DATABASE_FILE.
 */

use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutService;
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
        'changeouts' => $rows('select id, status, handoff_status, unit_slug, from_vehicle_number, to_vehicle_number, alert_id, return_of_changeout_id, submitted_by_name, note, client_submission_id from apparatus_changeouts order by id'),
        'items' => $rows('select i.id, i.apparatus_changeout_id as changeout_id, i.item_name, i.item_class, i.handling, i.custody_status, i.condition, i.note, i.quantity_moved, i.expected_quantity, i.verified_at from apparatus_changeout_items i order by i.id'),
    ];
}

switch ($command) {
    case 'reset':
        $result = FleetChangeoutE2ESeeder::world($argument);
        break;
    case 'state':
        $result = state();
        break;
    case 'release':
        $id = DB::table('apparatuses')->where('vehicle_number', $argument)->value('id');
        DB::table('apparatuses')->where('id', $id)->update(['status' => 'Available']);
        DB::table('apparatus_service_tickets')->where('apparatus_id', $id)->update(['status' => 'completed', 'completed_at' => now()]);
        $result = ['released' => $argument];
        break;
    case 'approve':
        $manager = User::query()->where('employee_id', '99503')->firstOrFail();
        $changeout = app(ApparatusChangeoutService::class)->decide((int) $argument, $manager, 'approve');
        $result = ['status' => $changeout->status];
        break;
    case 'clear-alert':
        DB::table('apparatus_operational_alerts')->where('unit_slug', $argument)->delete();
        $result = ['cleared' => $argument];
        break;
    default:
        fwrite(STDERR, "Unknown command {$command}\n");
        exit(2);
}

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
