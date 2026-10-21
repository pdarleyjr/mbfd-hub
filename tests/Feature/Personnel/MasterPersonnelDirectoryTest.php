<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\PersonnelTableEntry;
use App\Models\User;
use App\Services\Personnel\PersonnelTableExporter;
use App\Services\Personnel\PersonnelTableService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

final class MasterPersonnelDirectoryTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    public function test_directory_is_native_and_viewer_cannot_edit_profile_cells(): void
    {
        $employee = $this->employee('Taylor Fixture', '00125', 'Lieutenant');
        $this->actingAsAdminWith(['admin.personnel.view']);

        $this->get('/admin/master-personnel-directory')->assertOk()->assertSee('Master Personnel Directory');
        Livewire::test(MasterPersonnelDirectory::class)
            ->assertCanSeeTableRecords([$employee])
            ->call('updateTableColumnState', 'rank', (string) $employee->id, 'Captain');
        self::assertSame('Lieutenant', $employee->fresh()->rank);
    }

    public function test_profile_edit_uses_established_service_and_protected_identity_stays_unchanged(): void
    {
        $employee = $this->employee('Taylor Fixture', '00125', 'Lieutenant');
        $this->actingAsAdminWith(['admin.personnel.view', 'admin.personnel.manage']);

        Livewire::test(MasterPersonnelDirectory::class)
            ->call('updateTableColumnState', 'rank', (string) $employee->id, 'Captain')
            ->call('updateTableColumnState', 'employee_id', (string) $employee->id, '99999')
            ->call('updateTableColumnState', 'city_email', (string) $employee->id, 'changed@miamibeachfl.gov');

        self::assertSame('Captain', $employee->fresh()->rank);
        self::assertSame('00125', $employee->fresh()->employee_id);
        self::assertSame('fixture125@miamibeachfl.gov', $employee->fresh()->city_email);
        $this->assertDatabaseHas('employee_profile_events', ['employee_id' => $employee->id, 'action' => 'profile_updated', 'result' => 'success']);
    }

    public function test_search_combined_filters_and_multiple_sort_keys_use_authoritative_records(): void
    {
        $first = $this->employee('Alex Fixture', '00125', 'Captain', '1');
        $second = $this->employee('Zoe Fixture', '00126', 'Captain', '1');
        $other = $this->employee('Sam Fixture', '00127', 'Lieutenant', '2');
        $this->actingAsAdminWith(['admin.personnel.view']);

        Livewire::test(MasterPersonnelDirectory::class)
            ->filterTable('rank', ['Captain'])
            ->filterTable('station', ['1'])
            ->set('personnelSortRules', [['field' => 'rank', 'direction' => 'asc'], ['field' => 'name', 'direction' => 'desc']])
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('00125')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    }

    public function test_member_without_directory_access_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['account_status' => 'active']));
        self::assertNotSame(200, $this->get('/admin/master-personnel-directory')->getStatusCode());
        self::assertFalse(MasterPersonnelDirectory::canAccess());
    }

    public function test_directory_can_sort_tracking_numbers_and_save_the_multi_column_view(): void
    {
        $first = $this->employee('Alex Fixture', '00125');
        $second = $this->employee('Zoe Fixture', '00126');
        $actor = $this->actingAsAdminWith(['admin.personnel.view', 'admin.personnel.manage']);
        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);
        $number = (string) Str::uuid();
        $table = $service->update($actor, $table, ['columns' => [
            ['id' => $number, 'label' => 'Quantity', 'type' => 'number'],
        ]], $table->version);
        $service->setCell($actor, $table, $first, $number, 10, 0);
        $service->setCell($actor, $table, $second, $number, 2, 0);

        Livewire::test(MasterPersonnelDirectory::class)
            ->set('personnelSortRules', [['field' => $number, 'direction' => 'asc'], ['field' => 'name', 'direction' => 'asc']])
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->callAction('saveView', data: ['name' => 'Quantity then name']);

        $view = $table->views()->where('user_id', $actor->id)->firstOrFail();
        self::assertSame($number, $view->settings['personnelSortRules'][0]['field']);
    }

    public function test_csv_and_xlsx_export_use_persisted_data_and_safe_cell_types(): void
    {
        $employee = $this->employee('=1+1', '00125');
        $actor = $this->actingAsAdminWith(['admin.personnel.view', 'admin.personnel.manage']);
        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);
        $checkbox = (string) Str::uuid();
        $number = (string) Str::uuid();
        $table = $service->update($actor, $table, ['columns' => [
            ['id' => $checkbox, 'label' => '@Completed', 'type' => 'checkbox', 'options' => [], 'hidden' => false, 'color' => ''],
            ['id' => $number, 'label' => 'Quantity', 'type' => 'number', 'options' => [], 'hidden' => false, 'color' => ''],
        ]], $table->version);
        $entry = $service->setCell($actor, $table, $employee, $checkbox, true, 0);
        $service->setCell($actor, $table, $employee, $number, 3, $entry->version);
        $employee->name = 'UNSAVED SPOOF';
        $columns = ['employee_id', 'name', $checkbox, $number];
        $exporter = app(PersonnelTableExporter::class);

        $csv = $this->bytes($exporter->export($actor, $table, new Collection([$employee]), $columns, 'csv', 'selected'));
        self::assertStringContainsString("'=1+1", $csv);
        self::assertStringContainsString("'@Completed", $csv);
        self::assertStringNotContainsString('UNSAVED SPOOF', $csv);
        self::assertStringContainsString('00125', $csv);

        $bytes = $this->bytes($exporter->export($actor, $table, new Collection([$employee]), $columns, 'xlsx', 'selected'));
        $path = tempnam(sys_get_temp_dir(), 'personnel-test-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Personnel');
            self::assertSame('00125', $sheet->getCell('A2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
            self::assertSame('=1+1', $sheet->getCell('B2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
            self::assertTrue($sheet->getCell('C2')->getValue());
            self::assertSame(DataType::TYPE_BOOL, $sheet->getCell('C2')->getDataType());
            self::assertEquals(3, $sheet->getCell('D2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
            self::assertSame('selected', $book->getSheetByName('About this export')->getCell('B2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        self::assertSame(1, Employee::query()->where('employee_id', '00125')->count());
        self::assertSame('=1+1', $employee->fresh()->name);
    }

    public function test_export_permission_is_separate_from_directory_view(): void
    {
        $employee = $this->employee('Taylor Fixture', '00125');
        $actor = $this->actingAsAdminWith(['admin.personnel.view']);
        $table = app(PersonnelTableService::class)->master($actor);

        $this->expectException(AuthorizationException::class);
        app(PersonnelTableExporter::class)->export($actor, $table, new Collection([$employee]), ['name'], 'csv', 'selected');
    }

    public function test_export_rejects_unapproved_columns(): void
    {
        $employee = $this->employee('Taylor Fixture', '00125');
        $actor = $this->actingAsAdminWith(['admin.personnel.view', 'admin.personnel.manage']);
        $table = app(PersonnelTableService::class)->master($actor);

        $this->expectException(ValidationException::class);
        app(PersonnelTableExporter::class)->export($actor, $table, new Collection([$employee]), ['password'], 'csv', 'selected');
    }

    public function test_export_rechecks_fixed_membership_and_never_uses_a_foreign_row(): void
    {
        $included = $this->employee('Included Fixture', '00125');
        $outside = $this->employee('Outside Fixture', '00126');
        $actor = $this->actingAsSuperAdmin();
        $table = PersonnelTable::query()->create([
            'name' => 'Private fixture', 'created_by' => $actor->id, 'visibility' => 'private', 'membership_mode' => 'fixed',
            'source_columns' => ['name'], 'columns' => [], 'retired_columns' => [], 'criteria' => [], 'version' => 1,
        ]);
        PersonnelTableEntry::query()->create(['table_id' => $table->id, 'employee_id' => $included->id, 'values' => [], 'version' => 1]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PersonnelTableExporter::class)->export($actor, $table, new Collection([$outside]), ['name'], 'csv', 'selected');
    }

    public function test_pdf_contains_a_valid_document_without_changing_records(): void
    {
        $employee = $this->employee('<script>fixture</script>', '00125');
        $actor = $this->actingAsSuperAdmin();
        $table = app(PersonnelTableService::class)->master($actor);
        $pdf = $this->bytes(app(PersonnelTableExporter::class)->export($actor, $table, new Collection([$employee]), ['name', 'employee_id'], 'pdf', 'all'));

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        self::assertSame('<script>fixture</script>', $employee->fresh()->name);
    }

    private function employee(string $name, string $id, string $rank = 'Firefighter', string $station = '1'): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => $name, 'rank' => $rank,
            'station' => $station, 'roster_status' => 'active', 'city_email' => 'fixture'.(int) $id.'@miamibeachfl.gov']);
    }

    private function bytes(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
