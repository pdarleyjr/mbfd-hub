<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Enums\AccountStatus;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ListUnitMasterVehicles;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ViewUnitMasterVehicle;
use App\Models\Employee;
use App\Models\TakeHomeVehicleInspection;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\Fleet\UnitMasterRosterExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

final class UnitMasterRosterAdminTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStationAdmin();
    }

    public function test_fleet_viewer_can_open_the_list_and_the_vehicle_profile(): void
    {
        $vehicle = $this->vehicle(['veh_number' => '002-12', 'make' => 'Ford', 'model' => 'F-150']);
        $inspection = $this->inspection($vehicle, ['acceptable_for_use' => false]);
        $this->actingAsAdminWith(['admin.fleet.view']);

        $this->get('/admin/unit-master-vehicles')->assertOk();
        $this->get('/admin/unit-master-vehicles/'.$vehicle->getKey())
            ->assertOk()
            ->assertSee($inspection->reference)
            ->assertSee('Acceptable for use: No')
            ->assertSee(route('take-home-inspections.pdf', $inspection->reference), false);
        $this->get('/admin/take-home-vehicle-inspections')->assertOk();
    }

    public function test_ordinary_member_cannot_reach_the_fleet_admin_pages(): void
    {
        $vehicle = $this->vehicle();
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));

        foreach (['/admin/unit-master-vehicles', '/admin/unit-master-vehicles/'.$vehicle->getKey(), '/admin/take-home-vehicle-inspections'] as $url) {
            self::assertNotSame(200, $this->get($url)->getStatusCode(), $url);
        }
    }

    public function test_xlsx_export_keeps_identifiers_as_text(): void
    {
        $this->vehicle(['veh_number' => '002-12', 'tag_number' => '0123', 'serial_number' => '00098765']);
        $this->vehicle(['veh_number' => '10', 'tag_number' => 'ABC1']);

        $sheet = $this->loadXlsx(app(UnitMasterRosterExporter::class)->xlsx(
            UnitMasterVehicle::query()->orderBy('id')->get(),
            'All records',
            'None',
            now('America/New_York'),
        ))->getSheetByName('Vehicles');

        self::assertSame('Vehicle #', $sheet->getCell('A1')->getValue());
        self::assertSame('Needs review', $sheet->getCell('W1')->getValue());
        self::assertSame('002-12', $sheet->getCell('A2')->getValue());
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        self::assertSame('0123', $sheet->getCell('F2')->getValue());
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
        self::assertSame('00098765', $sheet->getCell('G2')->getValue());
        self::assertSame(3, $sheet->getHighestRow());
        self::assertSame('A2', $sheet->getFreezePane());
    }

    public function test_xlsx_about_sheet_describes_the_scope(): void
    {
        $this->vehicle();

        $spreadsheet = $this->loadXlsx(app(UnitMasterRosterExporter::class)->xlsx(
            UnitMasterVehicle::query()->get(),
            'Current filters & search',
            'Search: "x"',
            now('America/New_York'),
        ));
        $about = $spreadsheet->getSheetByName('About this export');

        self::assertSame('MBFD Master Unit Vehicle Inventory', $about->getCell('B1')->getValue());
        self::assertSame('Current filters & search', $about->getCell('B3')->getValue());
        self::assertSame('Search: "x"', $about->getCell('B4')->getValue());
        self::assertSame('1', $about->getCell('B5')->getValue());
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $vehicle = $this->vehicle(['make' => 'Ford']);
        $this->inspection($vehicle);

        $bytes = app(UnitMasterRosterExporter::class)->pdf(
            UnitMasterVehicle::query()->get(),
            'All records',
            'None',
            now('America/New_York'),
        );

        self::assertStringStartsWith('%PDF', $bytes);
    }

    public function test_filtered_export_uses_the_search_not_the_current_page(): void
    {
        $this->actingAsAdminWith(['admin.fleet.view']);
        $this->vehicle(['veh_number' => 'MATCH-1']);
        $this->vehicle(['veh_number' => 'OTHER-1']);
        $this->vehicle(['veh_number' => 'OTHER-2']);

        $filtered = Livewire::test(ListUnitMasterVehicles::class)
            ->loadTable()
            ->set('tableSearch', 'MATCH-1')
            ->callAction('exportExcel', data: ['scope' => 'filtered'])
            ->assertHasNoActionErrors();
        $all = Livewire::test(ListUnitMasterVehicles::class)
            ->loadTable()
            ->set('tableSearch', 'MATCH-1')
            ->callAction('exportExcel', data: ['scope' => 'all'])
            ->assertHasNoActionErrors();

        $filteredSheet = $this->downloadedSheet($filtered);
        self::assertSame(2, $filteredSheet->getHighestRow());
        self::assertSame('MATCH-1', $filteredSheet->getCell('A2')->getValue());
        self::assertSame(4, $this->downloadedSheet($all)->getHighestRow());

        $about = $this->loadXlsx($this->downloadBytes($filtered))->getSheetByName('About this export');
        self::assertSame('Search: "MATCH-1"', $about->getCell('B4')->getValue());
    }

    public function test_filtered_export_covers_more_than_one_page_and_honors_filters(): void
    {
        $this->actingAsAdminWith(['admin.fleet.view']);
        foreach (range(1, 55) as $number) {
            $this->vehicle(['veh_number' => sprintf('V-%03d', $number)]);
        }
        $this->vehicle(['veh_number' => 'T-1', 'asset_category' => 'trailer']);

        $everything = Livewire::test(ListUnitMasterVehicles::class)->loadTable()
            ->callAction('exportExcel', data: ['scope' => 'filtered']);
        self::assertSame(57, $this->downloadedSheet($everything)->getHighestRow());

        $trailers = Livewire::test(ListUnitMasterVehicles::class)->loadTable()
            ->set('tableFilters.asset_category.value', 'trailer')
            ->callAction('exportExcel', data: ['scope' => 'filtered']);
        $sheet = $this->downloadedSheet($trailers);
        self::assertSame(2, $sheet->getHighestRow());
        self::assertSame('T-1', $sheet->getCell('A2')->getValue());
    }

    public function test_pdf_action_downloads_a_pdf(): void
    {
        $this->actingAsAdminWith(['admin.fleet.view']);
        $this->vehicle();

        $test = Livewire::test(ListUnitMasterVehicles::class)->loadTable()
            ->callAction('exportPdf', data: ['scope' => 'all'])
            ->assertHasNoActionErrors();

        self::assertStringStartsWith('%PDF', $this->downloadBytes($test));
    }

    public function test_manager_can_accept_a_proposed_correction(): void
    {
        $vehicle = $this->vehicle(['color' => 'White']);
        $change = $this->proposal($vehicle, 'color', 'White', 'Red');
        $this->actingAsAdminWith(['admin.fleet.view', 'admin.fleet.manage']);

        Livewire::test(ViewUnitMasterVehicle::class, ['record' => $vehicle->getKey()])
            ->assertSee('acceptChange')
            ->callAction('acceptChange', arguments: ['change' => $change->getKey()]);

        self::assertSame('Red', $vehicle->fresh()->color);
        self::assertSame(UnitMasterVehicleChange::STATUS_ACCEPTED, $change->fresh()->status);
    }

    public function test_manager_can_reject_a_proposed_correction(): void
    {
        $vehicle = $this->vehicle(['color' => 'White']);
        $change = $this->proposal($vehicle, 'color', 'White', 'Red');
        $this->actingAsAdminWith(['admin.fleet.view', 'admin.fleet.manage']);

        Livewire::test(ViewUnitMasterVehicle::class, ['record' => $vehicle->getKey()])
            ->callAction('rejectChange', arguments: ['change' => $change->getKey()]);

        self::assertSame('White', $vehicle->fresh()->color);
        self::assertSame(UnitMasterVehicleChange::STATUS_REJECTED, $change->fresh()->status);
    }

    public function test_view_only_user_cannot_see_or_perform_accept(): void
    {
        $vehicle = $this->vehicle(['color' => 'White']);
        $change = $this->proposal($vehicle, 'color', 'White', 'Red');
        $this->actingAsAdminWith(['admin.fleet.view']);

        Livewire::test(ViewUnitMasterVehicle::class, ['record' => $vehicle->getKey()])
            ->assertSee('Proposed corrections')
            ->assertDontSee('acceptChange')
            ->assertActionHidden('acceptChange')
            ->call('mountAction', 'acceptChange', ['change' => $change->getKey()])
            ->call('callMountedAction');

        self::assertSame('White', $vehicle->fresh()->color);
        self::assertSame(UnitMasterVehicleChange::STATUS_PROPOSED, $change->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function vehicle(array $overrides = []): UnitMasterVehicle
    {
        $this->sequence++;

        return UnitMasterVehicle::query()->create(array_merge([
            'veh_number' => 'U-'.$this->sequence,
            'make' => 'Chevrolet',
            'model' => 'Tahoe',
            'year' => '2022',
            'dept_code' => 'FIRE',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function inspection(UnitMasterVehicle $vehicle, array $overrides = []): TakeHomeVehicleInspection
    {
        $this->sequence++;
        $inspector = User::factory()->create(['account_status' => AccountStatus::Active]);
        $employee = Employee::query()->create([
            'employee_id' => 'E'.$this->sequence,
            'name' => 'Driver '.$this->sequence,
            'rank' => 'Firefighter',
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ]);

        return TakeHomeVehicleInspection::query()->create(array_merge([
            'reference' => sprintf('THI-261021-%06d', $this->sequence),
            'client_submission_id' => (string) \Illuminate\Support\Str::uuid(),
            'unit_master_vehicle_id' => $vehicle->getKey(),
            'inspector_user_id' => $inspector->getKey(),
            'inspector_name' => 'Inspector '.$this->sequence,
            'driver_employee_id' => $employee->getKey(),
            'driver_name' => $employee->name,
            'driver_employee_number' => $employee->employee_id,
            'driver_verified' => true,
            'vehicle_snapshot' => ['veh_number' => $vehicle->veh_number, 'make' => $vehicle->make, 'model' => $vehicle->model],
            'odometer' => 12345,
            'checks' => [
                ['key' => 'exterior_body', 'status' => 'issue', 'issue_description' => 'Dent on door', 'notes' => null],
                ['key' => 'interior', 'status' => 'not_checked', 'issue_description' => null, 'notes' => null],
            ],
            'has_issues' => true,
            'has_not_checked' => true,
            'keys_present' => true,
            'acceptable_for_use' => true,
            'acknowledged_at' => now(),
            'signature_path' => 'take-home/signature.png',
            'signature_sha256' => str_repeat('a', 64),
            'submitted_at' => now(),
        ], $overrides));
    }

    private function proposal(UnitMasterVehicle $vehicle, string $field, ?string $old, string $new): UnitMasterVehicleChange
    {
        return UnitMasterVehicleChange::query()->create([
            'unit_master_vehicle_id' => $vehicle->getKey(),
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'status' => UnitMasterVehicleChange::STATUS_PROPOSED,
            'source' => UnitMasterVehicleChange::SOURCE_TAKE_HOME,
        ]);
    }

    private function downloadBytes(\Livewire\Features\SupportTesting\Testable $test): string
    {
        $content = data_get($test->effects, 'download.content');
        self::assertIsString($content, 'The action did not trigger a file download.');

        return (string) base64_decode($content, true);
    }

    private function downloadedSheet(\Livewire\Features\SupportTesting\Testable $test): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        return $this->loadXlsx($this->downloadBytes($test))->getSheetByName('Vehicles');
    }

    private function loadXlsx(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'roster-test-');
        file_put_contents($path, $bytes);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }
}
