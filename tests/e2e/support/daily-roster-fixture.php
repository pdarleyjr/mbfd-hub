<?php

declare(strict_types=1);

// Publishes a synthetic roster into the disposable protected-UI database. Run by the Daily Roster
// browser suite only; every name, ID and certification below is made up.
//   php daily-roster-fixture.php seed
//   php daily-roster-fixture.php publish "EXTRA, MEMBER"
//   php daily-roster-fixture.php publish-section "NEW, MEMBER"
//   php daily-roster-fixture.php age-pointer

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Tests\Support\DailyRosterSnapshotFactory;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('testing') || basename((string) config('database.connections.sqlite.database')) !== 'protected_ui_e2e.sqlite') {
    throw new RuntimeException('The Daily Roster fixture requires the disposable protected_ui_e2e.sqlite database.');
}

$certifications = ['AO', 'COWD', 'DE', 'DOQ', 'DR1', 'FBOQ', 'HMA', 'HMO', 'HMT', 'IADRS', 'INSP', 'ISO', 'LI', 'MCOFC', 'MML', 'PM', 'PSD', 'SF', 'SWP'];
$aDay = ['code' => '.ADONIS DAY', 'base_code' => 'ADONIS DAY', 'label' => 'A-Day', 'category' => 'A-Day', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING];
$overtime = ['code' => '+OT', 'category' => 'Overtime'];

$roster = static fn (array $extra = []): array => [
    'Division Chief / Division Chief 300' => [
        ['employee_id' => '90001', 'name' => 'TESTER, ADA', 'rank' => 'Division Chief', 'seat' => 0, 'code' => 'ADMS', 'category' => 'Leave', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'certifications' => ['HMA', 'INSP', 'PM']],
        ['employee_id' => '90002', 'name' => 'SAMPLE, BRETT', 'rank' => 'Division Chief', 'seat' => 0, 'continuation' => true, 'through' => '20:00', 'hours' => 12.0, 'certifications' => $certifications] + $overtime,
        ['employee_id' => '90002', 'name' => 'SAMPLE, BRETT', 'rank' => 'Division Chief', 'seat' => 0, 'continuation' => true, 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0, 'certifications' => $certifications] + $overtime,
    ],
    'Captain / Captain 5' => [
        ['employee_id' => '90003', 'name' => 'FIXTURE, CORA', 'rank' => 'Captain', 'code' => 'SP', 'category' => 'Scheduled', 'certifications' => ['DE', 'INSP']],
    ],
    'Station 1 / Ladder 1' => [
        ['employee_id' => '90002', 'name' => 'SAMPLE, BRETT', 'rank' => 'Combat Captain', 'seat' => 0, 'certifications' => $certifications] + $aDay,
        ['employee_id' => '90004', 'name' => 'VANDERHOOGENBAND-MONTGOMERY-WASHINGTON JR., MAXIMILIAN ALEXANDER', 'rank' => 'Combat Captain', 'seat' => 0, 'continuation' => true, 'code' => '+OT', 'category' => 'Overtime', 'through' => '20:00', 'hours' => 12.0, 'certifications' => ['COWD', 'DE', 'DR1']],
        ['employee_id' => '90005', 'name' => 'PLACEHOLDER, DANA', 'rank' => 'Combat Captain', 'seat' => 0, 'continuation' => true, 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0, 'code' => '.VACA', 'base_code' => 'VACA', 'label' => 'VACA', 'category' => 'Vacation', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING],
        ['employee_id' => '90006', 'name' => 'EXAMPLE, EVAN', 'rank' => 'Firefighter DE', 'seat' => 1, 'code' => '2WXON', 'category' => 'Exchange', 'certifications' => ['AO', 'DE', 'HMA']],
        ['employee_id' => '90007', 'name' => 'MOCK, FARAH', 'rank' => 'Firefighter', 'seat' => 2, 'code' => '2WXEON', 'category' => 'Exchange', 'certifications' => ['UNBROKENCERTIFICATIONSTRINGWITHNOSEPARATORSATALLTHATMUSTSTILLWRAPINSIDEITSCARD']],
    ],
    'Station 3 / Ladder 3' => [
        ['employee_id' => '90008', 'name' => 'DEMO, GRANT', 'rank' => 'Lieutenant', 'code' => 'EVAC', 'category' => 'Leave', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING],
    ],
    'Station 1 / Engine 1' => [
        ['employee_id' => '90009', 'name' => 'TRIAL, HANA', 'rank' => 'Combat Lieutenant', 'code' => 'SP', 'category' => 'Scheduled', 'certifications' => ['AO', 'DE']],
        ['kind' => RosterAssignment::KIND_VACANCY_OPEN, 'name' => '?,?', 'code' => null, 'rank' => 'Firefighter'],
        ['kind' => RosterAssignment::KIND_VACANCY_SUPPRESSED, 'name' => '-,-', 'code' => null, 'rank' => 'Firefighter'],
    ],
    'Station 1 / Rescue 1' => [
        ['employee_id' => '90010', 'name' => 'PILOT, IAN', 'rank' => 'Lieutenant', 'code' => 'SUPERLONGSTATUSCODEFROMTELESTAFF', 'availability' => RosterAssignment::AVAILABILITY_UNKNOWN, 'category' => 'Other'],
        ['employee_id' => '90011', 'name' => 'BETA, JOY', 'rank' => 'Firefighter', 'code' => 'SP', 'category' => 'Scheduled'],
        ...$extra,
    ],
    'Station 4 / Fire Boat 5' => [
        ['employee_id' => '90012', 'name' => 'GAMMA, KAI', 'rank' => 'Firefighter', 'code' => 'SP', 'category' => 'Scheduled'],
    ],
    'Station 2 / Air Truck 810' => [
        ['employee_id' => '90013', 'name' => 'DELTA, LEE', 'rank' => 'Firefighter', 'code' => 'OT', 'category' => 'Overtime'],
    ],
    'Float / Rescue Float' => [
        ['employee_id' => '90014', 'name' => 'OMEGA, MAE', 'rank' => 'Firefighter', 'code' => 'SP', 'category' => 'Scheduled'],
    ],
];

$calendar = app(DutyCalendar::class);
$dutyDate = $calendar->dutyDate(CarbonImmutable::now());
$command = $argv[1] ?? 'seed';

// The publisher stores the rank on the seat only for a continuation row; the rows here say the same.
$blankContinuationRanks = static fn () => RosterAssignment::query()->where('is_continuation', true)->update(['rank_label' => null]);

if ($command === 'seed') {
    DailyRosterSnapshotFactory::publish($dutyDate->subDay()->format('Y-m-d'), [
        'Station 2 / Engine 2' => [['employee_id' => '90015', 'name' => 'YESTERDAY, NOA', 'rank' => 'Firefighter', 'code' => 'SP', 'category' => 'Scheduled']],
    ]);
    DailyRosterSnapshotFactory::publish($dutyDate->format('Y-m-d'), $roster());
    $blankContinuationRanks();
} elseif ($command === 'publish' && isset($argv[2])) {
    DailyRosterSnapshotFactory::publish($dutyDate->format('Y-m-d'), $roster([
        ['employee_id' => '90016', 'name' => $argv[2], 'rank' => 'Firefighter', 'code' => 'SP', 'category' => 'Scheduled'],
    ]), ['generated_at' => CarbonImmutable::now()]);
    $blankContinuationRanks();
} elseif ($command === 'publish-section' && isset($argv[2])) {
    DailyRosterSnapshotFactory::publish($dutyDate->format('Y-m-d'), $roster() + [
        'Station 6 / Engine 6' => [['employee_id' => '90017', 'name' => $argv[2], 'rank' => 'Firefighter', 'code' => 'SP', 'category' => 'Scheduled']],
    ], ['generated_at' => CarbonImmutable::now()]);
    $blankContinuationRanks();
} elseif ($command === 'age-pointer') {
    // So a later publish changes "Last updated" even when it lands in the same minute as the one before it.
    RosterActiveVersion::query()->whereDate('duty_date', $dutyDate->format('Y-m-d'))->toBase()
        ->update(['updated_at' => CarbonImmutable::now()->subHours(3)->format('Y-m-d H:i:s')]);
} else {
    throw new InvalidArgumentException('Usage: daily-roster-fixture.php seed | publish "NAME" | publish-section "NAME" | age-pointer');
}
