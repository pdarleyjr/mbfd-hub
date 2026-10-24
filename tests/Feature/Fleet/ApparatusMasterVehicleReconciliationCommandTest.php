<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fleet\Concerns\BuildsMasterLinkFixtures;
use Tests\TestCase;

/**
 * fleet:link-master-vehicles over rows shaped like production: exact matches with blank or
 * drifted fields, a Captain 5 style VIN conflict, a blank vehicle number, duplicate master
 * numbers, a truck with no master, and master-only road vehicles.
 */
final class ApparatusMasterVehicleReconciliationCommandTest extends TestCase
{
    use BuildsMasterLinkFixtures;
    use RefreshDatabase;

    /** @var array<string, Apparatus> */
    private array $trucks = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        $this->buildFleet();
    }

    private function buildFleet(): void
    {
        $this->master('20503', ['make' => 'PIERCE', 'model' => 'VELOCITY', 'year' => '2021', 'serial_number' => 'TESTVIN000000001']);
        $this->master('002-16', ['make' => 'PIERCE', 'year' => '2011']);
        $this->master('16507', ['make' => 'FREIGHTLINER', 'model' => 'M2', 'year' => '2016', 'serial_number' => 'TESTVIN000000002']);
        $this->master('22530', ['make' => 'Ford', 'model' => 'F-250', 'year' => '2022', 'serial_number' => 'TESTVIN000000530']);
        $this->master('24509', ['make' => 'PIERCE', 'year' => '2020']);
        $this->master('17501', ['make' => 'FREIGHTLINER', 'year' => '2017']);
        $this->master('3000', ['section' => 'Utility Vehicles']);
        $this->master('3000', ['section' => 'Utility Vehicles']);
        // Master only: road vehicles in the apparatus section with no apparatus row.
        $this->master('1031', ['make' => 'Ford', 'model' => 'Explorer', 'year' => '2012']);
        $this->master('002-10', ['make' => 'Ford', 'model' => 'F-150', 'year' => '2010']);
        $this->master('002-4', ['asset_category' => null, 'make' => 'Chevrolet', 'year' => '2004']);
        // Not apparatus-class road vehicles: never listed.
        $this->master('TR-1', ['asset_category' => 'trailer']);
        $this->master('1040', ['section' => 'Utility Vehicles']);

        $this->trucks = [
            'E1' => $this->legacyApparatus('E1', '20503'),
            'E21' => $this->legacyApparatus('E21', '002-16', ['make' => 'Pierce']),
            'R2' => $this->legacyApparatus('R2', '16507', ['year' => 2015, 'make' => 'FREIGHTLINER', 'model' => 'M2', 'vin' => 'testvin-000000002']),
            'Captain 5' => $this->legacyApparatus('Captain 5', '22530', ['year' => 2024, 'make' => 'Chevrolet', 'model' => 'Tahoe', 'vin' => 'TESTVIN000000631']),
            'FB6' => $this->legacyApparatus('FB6', null),
            'X9' => $this->legacyApparatus('X9', '3000'),
            'ZZV1' => $this->legacyApparatus('ZZV1', 'ZZ-V1'),
            'E2' => $this->legacyApparatus('E2', '24509'),
            'R3' => $this->legacyApparatus('R3', '17501'),
        ];
        // E2 is already linked and clean; R3 is linked but the master moved on afterwards.
        $link = app(ApparatusMasterVehicleLink::class);
        $link->reconcile($this->trucks['E2']);
        $link->reconcile($this->trucks['R3']);
        DB::table('unit_master_vehicles')->where('veh_number', '17501')->update(['model' => 'M2 106']);
    }

    /** @return array<string, mixed> everything a dry run must leave exactly as it was */
    private function snapshot(): array
    {
        return [
            'apparatuses' => Apparatus::query()->orderBy('id')->get(['id', 'vehicle_number', 'make', 'model', 'year', 'vin', 'unit_master_vehicle_id', 'master_link_status', 'master_link_note'])->toArray(),
            'masters' => UnitMasterVehicle::query()->orderBy('id')->get()->toArray(),
            'changes' => UnitMasterVehicleChange::query()->orderBy('id')->get()->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runCommand(array $options = []): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'link-report-');
        $exit = Artisan::call('fleet:link-master-vehicles', $options + ['--json' => $path]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        @unlink($path);
        $report['console'] = $output;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, array<string, mixed>>
     */
    private function byUnit(array $report): array
    {
        return collect($report['apparatuses'])->keyBy('unit')->all();
    }

    public function test_dry_run_writes_nothing(): void
    {
        // A real (database) queue honours "after commit", so a rolled back dry run queues nothing.
        config()->set('google_sheets.apparatus_sync_enabled', true);
        config()->set('queue.default', 'database');
        $before = $this->snapshot();

        $report = $this->runCommand(['--dry-run' => true]);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertTrue($report['dry_run']);
        $this->assertStringContainsString('DRY RUN - nothing was written.', $report['console']);
        $this->assertStringContainsString('Changes that would be written', $report['console']);
        $this->assertSame(8, $report['summary']['changes'], 'the report still shows what a real run would do');

        // Control: the same run for real does queue the sheet sync, so the zero above means something.
        $this->runCommand();
        $this->assertGreaterThan(0, DB::table('jobs')->count());
    }

    public function test_real_run_links_corrects_and_flags(): void
    {
        $mastersBefore = UnitMasterVehicle::query()->count();

        $report = $this->runCommand();
        $units = $this->byUnit($report);

        $this->assertSame('linked', $units['E1']['outcome']);
        $this->assertSame('linked', $units['E21']['outcome']);
        $this->assertSame('linked', $units['R2']['outcome']);
        $this->assertSame('already linked', $units['E2']['outcome']);
        $this->assertSame('corrected', $units['R3']['outcome']);
        $this->assertSame('needs review', $units['Captain 5']['outcome']);
        $this->assertSame('needs review', $units['FB6']['outcome']);
        $this->assertSame('needs review', $units['X9']['outcome']);
        $this->assertSame('needs review', $units['ZZV1']['outcome']);
        $this->assertSame(
            ['apparatuses' => 9, 'linked' => 3, 'corrected' => 1, 'already_linked' => 1, 'needs_review' => 4, 'changes' => 8, 'master_only' => 4],
            $report['summary'],
        );
        $this->assertSame($mastersBefore, UnitMasterVehicle::query()->count(), 'the command never creates master rows');

        $e1 = $this->trucks['E1']->fresh();
        $this->assertSame(['PIERCE', 'VELOCITY', 2021, 'TESTVIN000000001', 'linked'], [$e1->make, $e1->model, $e1->year, $e1->vin, $e1->master_link_status]);
        $r2 = $this->trucks['R2']->fresh();
        $this->assertSame(2016, $r2->year);
        $this->assertSame('testvin-000000002', $r2->vin, 'an equivalent VIN spelling is not a difference');
        $yearRow = collect($units['R2']['fields'])->firstWhere('field', 'year');
        $this->assertSame(['2015', '2016', 'apparatus'], [$yearRow['from'], $yearRow['to'], $yearRow['side']]);
        $this->assertStringContainsString('Year (apparatus copy): 2015 -> 2016', $report['console']);
        $this->assertSame('M2 106', $this->trucks['R3']->fresh()->model);

        $this->assertSame('No master vehicle has number ZZ-V1.', $units['ZZV1']['reason']);
        $this->assertSame('2 master vehicles share number 3000.', $units['X9']['reason']);
        $this->assertSame('No vehicle number on this apparatus.', $units['FB6']['reason']);
        $this->assertSame('VIN on this apparatus (TESTVIN000000631) does not match master vehicle 22530 (TESTVIN000000530).', $units['Captain 5']['reason']);
        $captain = $this->trucks['Captain 5']->fresh();
        $this->assertNull($captain->unit_master_vehicle_id);
        $this->assertSame(2024, $captain->year);

        $audit = UnitMasterVehicleChange::query()->where('source', 'apparatus_link')->where('field', 'year')->get();
        $this->assertContains('2016', $audit->pluck('new_value')->all());
        $this->assertSame(['applied'], $audit->pluck('status')->unique()->values()->all());
    }

    public function test_second_run_reports_zero_changes(): void
    {
        $first = $this->runCommand();
        $this->assertGreaterThan(0, $first['summary']['changes']);
        $snapshot = $this->snapshot();

        $second = $this->runCommand();

        $this->assertSame(0, $second['summary']['changes']);
        $this->assertSame(0, $second['summary']['linked'] + $second['summary']['corrected']);
        $this->assertSame(5, $second['summary']['already_linked']);
        $this->assertSame(4, $second['summary']['needs_review']);
        $this->assertSame($snapshot, $this->snapshot(), 'a second run writes nothing');
        $this->assertSame(array_column($first['apparatuses'], 'apparatus_id'), array_column($second['apparatuses'], 'apparatus_id'), 'rows are reported in id order');
    }

    public function test_dry_run_report_matches_what_the_real_run_then_does(): void
    {
        $dry = $this->runCommand(['--dry-run' => true]);
        $real = $this->runCommand();

        $this->assertSame($dry['apparatuses'], $real['apparatuses']);
        $this->assertSame($dry['master_only'], $real['master_only']);
        $this->assertSame($dry['summary'], $real['summary']);
        $this->assertFalse($real['dry_run']);
    }

    public function test_master_only_list_names_frontline_road_vehicles_without_an_apparatus(): void
    {
        $report = $this->runCommand();

        // 22530 is the master truck the Captain 5 apparatus wrongly shares a number with: it has no apparatus of its own.
        $this->assertSame(['002-10', '002-4', '1031', '22530'], collect($report['master_only'])->pluck('vehicle_number')->sort()->values()->all());
        $this->assertStringContainsString('Master only (for Fleet review)', $report['console']);
        $this->assertSame('Ford', collect($report['master_only'])->firstWhere('vehicle_number', '1031')['make']);
    }

    public function test_json_report_is_written_where_asked_and_a_bad_folder_is_refused(): void
    {
        $report = $this->runCommand();

        $this->assertSame(['generated_at', 'dry_run', 'summary', 'apparatuses', 'master_only'], array_slice(array_keys($report), 0, 5));
        $this->assertCount(9, $report['apparatuses']);

        $exit = Artisan::call('fleet:link-master-vehicles', ['--json' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'no-such-folder'.DIRECTORY_SEPARATOR.'r.json']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('The folder for --json does not exist', Artisan::output());
    }
}
