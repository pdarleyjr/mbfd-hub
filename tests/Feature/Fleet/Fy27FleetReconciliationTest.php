<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Services\Fleet\Fy27FleetReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Synthetic fixtures only: the FY27 sample CSV and the Hub rows below contain no real
 * people, plates or VINs.
 */
final class Fy27FleetReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->csv = base_path('tests/Fixtures/fleet/fy27_sample.csv');
        $this->seedHub();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_dry_run_writes_nothing_and_says_so(): void
    {
        $before = UnitMasterVehicle::query()->orderBy('id')->get()->toArray();

        $this->artisan('fleet:reconcile-fy27', ['csv' => $this->csv])
            ->expectsOutputToContain('DRY RUN - no changes written.')
            ->assertExitCode(0);

        $this->assertSame($before, UnitMasterVehicle::query()->orderBy('id')->get()->toArray());
        $this->assertSame(0, UnitMasterVehicleChange::query()->count());
    }

    public function test_totals_equal_csv_rows_and_classes_are_as_expected(): void
    {
        $reconciler = app(Fy27FleetReconciler::class);
        $rows = $reconciler->parse($this->csv);
        $plan = $reconciler->plan($rows);

        $this->assertCount(13, $rows);
        $this->assertSame(13, $plan['csv_rows']);
        $this->assertSame(13, array_sum($plan['totals']));
        $this->assertSame([
            Fy27FleetReconciler::CLASS_EXACT => 4,
            Fy27FleetReconciler::CLASS_DISCREPANCY => 3,
            Fy27FleetReconciler::CLASS_VIN_MATCH => 1,
            Fy27FleetReconciler::CLASS_MISSING => 2,
            Fy27FleetReconciler::CLASS_CONFLICT => 2,
            Fy27FleetReconciler::CLASS_VERIFY => 1,
        ], $plan['totals']);
        $this->assertSame(2, $plan['hidden_rows']);
        $this->assertSame(
            ['9999'],
            array_map(fn (array $hub): string => (string) $hub['veh_number'], $plan['absent_from_fy27']),
        );
        $this->assertSame(
            ['fills' => 36, 'refinements' => 1, 'proposals_new' => 4, 'proposals_already_pending' => 0, 'creates' => 2],
            $plan['counts'],
        );
    }

    public function test_apply_enriches_audits_and_creates(): void
    {
        $this->artisan('fleet:reconcile-fy27', ['csv' => $this->csv, '--apply' => true])
            ->expectsOutputToContain('APPLIED')
            ->assertExitCode(0);

        // Refinement is applied and audited with old/new.
        $this->assertSame('ESCAPE SE PHEV F', $this->hub('2001')->model);
        $this->assertChange('2001', 'model', UnitMasterVehicleChange::STATUS_APPLIED, 'Escape', 'ESCAPE SE PHEV F', 'FY27 row 3');
        $this->assertSame('325', $this->hub('2001')->dept_code, 'leading-zero department is the same code');

        // "N/A" tag and an empty VIN are filled.
        $this->assertSame('QW5678', $this->hub('2002')->tag_number);
        $this->assertChange('2002', 'tag_number', UnitMasterVehicleChange::STATUS_APPLIED, 'N/A', 'QW5678', 'FY27 row 4');
        $this->assertSame('TESTVIN2003AAAAAA', $this->hub('2003')->serial_number);
        $this->assertNull($this->hub('2003')->fleet_status_code, 'blank FY27 status stays null');
        $this->assertSame('Gray', $this->hub('2003')->color);

        // New columns and category are filled on exact matches.
        $exact = $this->hub('1665');
        $this->assertSame('White', $exact->color);
        $this->assertSame('P', $exact->fleet_status_code);
        $this->assertSame('121F', $exact->fleet_class_code);
        $this->assertSame('CAR FWD FULL SIZE-ADMIN-121F', $exact->fleet_class_description);
        $this->assertSame('vehicle', $exact->asset_category);

        // Year conflict is only proposed.
        $this->assertSame('2015', $this->hub('2004')->year);
        $this->assertChange('2004', 'year', UnitMasterVehicleChange::STATUS_PROPOSED, '2015', '2016', 'FY27 row 6');

        // Names are never overwritten; a token match is not a change at all.
        $this->assertSame('Kevin Doe', $this->hub('2005')->employee_or_vehicle_name);
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('field', 'employee_or_vehicle_name')
            ->where('unit_master_vehicle_id', $this->hub('2005')->id)->count());
        $this->assertSame('Alex Sample', $this->hub('2006')->employee_or_vehicle_name);
        $this->assertChange('2006', 'employee_or_vehicle_name', UnitMasterVehicleChange::STATUS_PROPOSED, 'Alex Sample', 'R. OTHER', 'FY27 row 8');

        // VIN conflict: serial proposal only, nothing else touched.
        $conflict = $this->hub('2007');
        $this->assertSame('TESTVIN2007AAAAAA', $conflict->serial_number);
        $this->assertNull($conflict->color);
        $this->assertNull($conflict->asset_category);
        $this->assertSame(1, UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $conflict->id)->count());
        $this->assertChange('2007', 'serial_number', UnitMasterVehicleChange::STATUS_PROPOSED, 'TESTVIN2007AAAAAA', 'TESTVIN2007BBBBBB', 'FY27 row 9');

        // VIN-only match proposes the vehicle number and changes nothing else.
        $vinMatch = $this->hub('2008');
        $this->assertSame('2008', $vinMatch->veh_number);
        $this->assertNull($vinMatch->color);
        $this->assertChange('2008', 'veh_number', UnitMasterVehicleChange::STATUS_PROPOSED, '2008', '2999', 'FY27 row 10');
        $this->assertSame(0, UnitMasterVehicle::query()->where('veh_number', '2999')->count());

        // Duplicate Hub vehicle numbers: no writes at all.
        $duplicateIds = UnitMasterVehicle::query()->where('veh_number', '2100')->pluck('id');
        $this->assertCount(2, $duplicateIds);
        $this->assertSame(0, UnitMasterVehicleChange::query()->whereIn('unit_master_vehicle_id', $duplicateIds)->count());

        // Missing rows are created with source strings preserved and audited.
        $trailer = $this->hub('3001');
        $this->assertSame('1WC200R3763054938', $trailer->serial_number);
        $this->assertSame('1240', $trailer->dept_code);
        $this->assertSame('trailer', $trailer->asset_category);
        $this->assertSame('R', $trailer->fleet_status_code);
        $this->assertSame('230181', $trailer->tag_number);
        $this->assertSame(UnitMasterVehicleChange::SOURCE_FY27, $trailer->record_source);
        $this->assertFalse($trailer->needs_fleet_review);
        $this->assertDatabaseHas('unit_master_vehicle_changes', [
            'unit_master_vehicle_id' => $trailer->id,
            'field' => 'record',
            'new_value' => 'created',
            'source' => UnitMasterVehicleChange::SOURCE_FY27,
            'source_reference' => 'FY27 row 11',
        ]);
        $motor = $this->hub('3002');
        $this->assertSame('3B375794', $motor->serial_number, 'short boat-motor serials are never padded');
        $this->assertSame('boat_motor', $motor->asset_category);
        $this->assertNull($motor->fleet_status_code);
        $this->assertNull($motor->tag_number);

        $this->assertSame(14, UnitMasterVehicle::query()->count());
        $this->assertSame(0, UnitMasterVehicle::query()->whereNotNull('current_driver_employee_id')->count());
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('field', 'current_driver_employee_id')->count());
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('field', 'assignment')->count());
    }

    public function test_hub_only_records_are_never_touched(): void
    {
        $hubOnly = $this->hub('9999');
        $snapshot = $hubOnly->getAttributes();

        $this->artisan('fleet:reconcile-fy27', ['csv' => $this->csv, '--apply' => true])->assertExitCode(0);

        $this->assertSame($snapshot, UnitMasterVehicle::query()->findOrFail($hubOnly->id)->getAttributes());
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $hubOnly->id)->count());
    }

    public function test_second_apply_is_a_no_op(): void
    {
        $this->artisan('fleet:reconcile-fy27', ['csv' => $this->csv, '--apply' => true])->assertExitCode(0);
        $vehicles = UnitMasterVehicle::query()->orderBy('id')->get()->toArray();
        $changes = UnitMasterVehicleChange::query()->count();

        $this->artisan('fleet:reconcile-fy27', ['csv' => $this->csv, '--apply' => true])
            ->expectsOutputToContain('Written now: 0 applied change(s), 0 proposal(s), 0 created record(s)')
            ->assertExitCode(0);

        $this->assertSame($vehicles, UnitMasterVehicle::query()->orderBy('id')->get()->toArray());
        $this->assertSame($changes, UnitMasterVehicleChange::query()->count());
        $this->assertSame(1, UnitMasterVehicleChange::query()->where('field', 'year')->where('status', 'proposed')->count());
    }

    public function test_json_report_is_written_and_omits_names(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fy27_report_'.uniqid().'.json';
        $this->tempFiles[] = $path;

        $this->artisan('fleet:reconcile-fy27', ['csv' => $this->csv, '--report' => $path])->assertExitCode(0);

        $json = (string) file_get_contents($path);
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame(13, $report['csv_rows']);
        $this->assertSame(13, $report['totals_sum']);
        $this->assertSame(2, $report['hidden_rows']);
        $this->assertTrue($report['hidden_rows_included']);
        $this->assertStringNotContainsString('R. OTHER', $json);
        $this->assertStringNotContainsString('Alex Sample', $json);
        $this->assertStringNotContainsString('Kevin Doe', $json);
        $this->assertStringContainsString('"name_differs": true', $json);
        $this->assertStringContainsString('ESCAPE SE PHEV F', $json);
        $this->assertSame(0, UnitMasterVehicleChange::query()->count());
    }

    public function test_wrong_header_is_rejected_without_writes(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fy27_bad_'.uniqid().'.csv';
        $this->tempFiles[] = $path;
        file_put_contents($path, "Vehicle No,Make\n1,FORD\n");

        $this->artisan('fleet:reconcile-fy27', ['csv' => $path, '--apply' => true])
            ->expectsOutputToContain('header does not match')
            ->assertExitCode(1);

        $this->assertSame(12, UnitMasterVehicle::query()->count());
        $this->assertSame(0, UnitMasterVehicleChange::query()->count());
    }

    public function test_missing_file_fails_cleanly(): void
    {
        $this->artisan('fleet:reconcile-fy27', ['csv' => base_path('tests/Fixtures/fleet/does_not_exist.csv')])
            ->assertExitCode(1);
    }

    private function hub(string $vehNumber): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->where('veh_number', $vehNumber)->orderBy('id')->firstOrFail();
    }

    private function assertChange(string $vehNumber, string $field, string $status, ?string $old, ?string $new, string $reference): void
    {
        $this->assertDatabaseHas('unit_master_vehicle_changes', [
            'unit_master_vehicle_id' => $this->hub($vehNumber)->id,
            'field' => $field,
            'status' => $status,
            'old_value' => $old,
            'new_value' => $new,
            'source' => UnitMasterVehicleChange::SOURCE_FY27,
            'source_reference' => $reference,
        ]);
    }

    private function seedHub(): void
    {
        $rows = [
            ['veh_number' => '1665', 'make' => 'Ford', 'model' => 'Taurus SE', 'year' => '2013', 'tag_number' => 'XD0409', 'serial_number' => '1FAHP2D85DG117005', 'dept_code' => '1210'],
            ['veh_number' => '2001', 'make' => 'Ford', 'model' => 'Escape', 'year' => '2020', 'tag_number' => 'AB1234', 'serial_number' => 'TESTVIN2001AAAAAA', 'dept_code' => '325'],
            ['veh_number' => '2002', 'make' => 'Chevrolet', 'model' => 'Tahoe', 'year' => '2018', 'tag_number' => 'N/A', 'serial_number' => 'TESTVIN2002AAAAAA', 'dept_code' => '1210'],
            ['veh_number' => '2003', 'make' => 'Dodge', 'model' => 'Charger', 'year' => '2017', 'tag_number' => 'ER9012', 'serial_number' => null, 'dept_code' => '1210'],
            ['veh_number' => '2004', 'make' => 'Toyota', 'model' => 'Camry', 'year' => '2015', 'tag_number' => 'TY3456', 'serial_number' => 'TESTVIN2004AAAAAA', 'dept_code' => '1210'],
            ['veh_number' => '2005', 'make' => 'Ford', 'model' => 'F150', 'year' => '2019', 'tag_number' => 'UI7890', 'serial_number' => 'TESTVIN2005AAAAAA', 'dept_code' => '1220', 'employee_or_vehicle_name' => 'Kevin Doe'],
            ['veh_number' => '2006', 'make' => 'Ford', 'model' => 'F250', 'year' => '2019', 'tag_number' => 'OP1234', 'serial_number' => 'TESTVIN2006AAAAAA', 'dept_code' => '1220', 'employee_or_vehicle_name' => 'Alex Sample'],
            ['veh_number' => '2007', 'make' => 'Honda', 'model' => 'Civic', 'year' => '2014', 'tag_number' => 'AS5678', 'serial_number' => 'TESTVIN2007AAAAAA', 'dept_code' => '1210'],
            ['veh_number' => '2008', 'make' => 'Nissan', 'model' => 'Altima', 'year' => '2015', 'tag_number' => 'DF9012', 'serial_number' => 'TESTVIN2008AAAAAA', 'dept_code' => '1210'],
            ['veh_number' => '2100', 'make' => 'Ford', 'model' => 'Ranger', 'year' => '2011', 'tag_number' => 'JK7890', 'serial_number' => 'TESTVIN2100AAAAAA', 'dept_code' => '1210'],
            ['veh_number' => '2100', 'make' => 'Ford', 'model' => 'Ranger', 'year' => '2011', 'tag_number' => null, 'serial_number' => null, 'dept_code' => '1210'],
            ['veh_number' => '9999', 'make' => 'Hub', 'model' => 'Only', 'year' => '2001', 'tag_number' => 'HUBONLY', 'serial_number' => 'HUBONLYSERIAL1', 'dept_code' => '1230'],
        ];
        foreach ($rows as $row) {
            UnitMasterVehicle::query()->create($row);
        }
    }
}
