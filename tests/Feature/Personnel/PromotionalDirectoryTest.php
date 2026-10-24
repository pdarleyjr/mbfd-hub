<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Models\Employee;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Services\Personnel\PersonnelSource;
use App\Services\Personnel\PersonnelTableExporter;
use App\Services\Personnel\PersonnelTableService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** Promotional list fields in the Master Personnel Directory: values, sort, filter, export, saved views. */
final class PromotionalDirectoryTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private const PROMO = ['promotional_list', 'promotional_rank', 'promotional_status', 'promotional_expires'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    public function test_the_four_promotional_fields_are_in_the_column_chooser_but_hidden_until_chosen(): void
    {
        $this->actingAsAdminWith(['personnel.directory.view']);

        $page = Livewire::test(MasterPersonnelDirectory::class);
        foreach (self::PROMO as $key) {
            self::assertArrayHasKey($key, app(PersonnelSource::class)->fields());
            $column = $page->instance()->getTable()->getColumn($key);
            self::assertNotNull($column, $key.' is in the table');
            self::assertTrue($column->isToggleable());
            self::assertTrue($column->isToggledHiddenByDefault());
        }
    }

    public function test_values_show_every_list_in_effect_and_ignore_drafts_expired_and_deleted_lists(): void
    {
        [$ann, $bo, $cy] = [$this->employee('Ann Fixture'), $this->employee('Bo Fixture'), $this->employee('Cy Fixture')];
        $lieutenant = $this->list('lieutenant', 'Lieutenant Results 2026', '2026-04-01', '2027-04-01');
        $captain = $this->list('captain', 'Captain Results 2025', '2025-04-01', null);
        $this->entry($lieutenant, 2, $ann, 'eligible', 1);
        $this->entry($lieutenant, 3, $bo, 'eligible', 2);
        $this->entry($captain, 5, $bo, 'eligible', 4);
        // None of these count: a draft, an expired list, a deleted list, and a person who was promoted off the list.
        $this->entry($this->list('driver_engineer', 'Draft DE', '2026-01-01', null, 'draft'), 2, $cy, 'eligible', null);
        $this->entry($this->list('division_chief', 'Old DC', '2024-01-01', '2025-01-01', 'expired'), 2, $cy, 'eligible', null);
        $deleted = $this->list('other', 'Deleted list', '2026-02-01', null, 'active', 'Special');
        $this->entry($deleted, 2, $cy, 'eligible', 1);
        $deleted->delete();

        $source = new PersonnelSource;
        $rows = $source->query()->get()->keyBy('name');
        self::assertSame('Lieutenant - Lieutenant Results 2026', $source->value($rows['Ann Fixture'], 'promotional_list'));
        self::assertSame('1', $source->value($rows['Ann Fixture'], 'promotional_rank'));
        self::assertSame('Eligible', $source->value($rows['Ann Fixture'], 'promotional_status'));
        self::assertSame('Apr 1, 2027', $source->value($rows['Ann Fixture'], 'promotional_expires'));
        // On two lists: both are shown, newest effective date first, each labeled.
        self::assertSame('Lieutenant - Lieutenant Results 2026; Captain - Captain Results 2025', $source->value($rows['Bo Fixture'], 'promotional_list'));
        self::assertSame('Lieutenant: 2; Captain: 4', $source->value($rows['Bo Fixture'], 'promotional_rank'));
        self::assertSame('Lieutenant: Apr 1, 2027; Captain: No expiry date', $source->value($rows['Bo Fixture'], 'promotional_expires'));
        foreach (self::PROMO as $key) {
            self::assertNull($source->value($rows['Cy Fixture'], $key), $key);
        }
    }

    public function test_a_member_promoted_off_a_list_shows_the_status_and_no_rank(): void
    {
        $ann = $this->employee('Ann Fixture');
        $list = $this->list('lieutenant', 'Lieutenant Results 2026', '2026-04-01', null);
        $this->entry($list, 2, $ann, 'promoted', null);

        $source = new PersonnelSource;
        $row = $source->query()->get()->firstWhere('name', 'Ann Fixture');

        self::assertSame('Not ranked', $source->value($row, 'promotional_rank'));
        self::assertSame('Promoted', $source->value($row, 'promotional_status'));
        self::assertSame([], $source->filter($source->query(), ['promotional_on_list' => true])->pluck('employees.name')->all());
        self::assertSame(['Ann Fixture'], $source->filter($source->query(), ['promotional_list_type' => ['lieutenant']])->pluck('employees.name')->all());
    }

    public function test_the_directory_filters_by_list_type_and_by_being_on_an_active_list(): void
    {
        [$ann, $bo, $cy] = [$this->employee('Ann Fixture'), $this->employee('Bo Fixture'), $this->employee('Cy Fixture')];
        $this->entry($this->list('lieutenant', 'Lieutenant Results 2026', '2026-04-01', null), 2, $ann, 'eligible', 1);
        $this->entry($this->list('captain', 'Captain Results 2026', '2026-05-01', null), 2, $bo, 'eligible', 1);
        $this->actingAsAdminWith(['personnel.directory.view']);

        Livewire::test(MasterPersonnelDirectory::class)
            ->filterTable('promotional_on_list', true)
            ->assertCanSeeTableRecords([$ann, $bo])->assertCanNotSeeTableRecords([$cy])
            ->removeTableFilters()
            ->filterTable('promotional_list_type', ['captain'])
            ->assertCanSeeTableRecords([$bo])->assertCanNotSeeTableRecords([$ann, $cy]);
    }

    public function test_the_directory_sorts_by_promotional_rank_with_unranked_members_last_in_both_directions(): void
    {
        $first = $this->employee('Zed Fixture');
        $second = $this->employee('Yan Fixture');
        $none = $this->employee('Abe Fixture');
        $list = $this->list('lieutenant', 'Lieutenant Results 2026', '2026-04-01', null);
        $this->entry($list, 2, $first, 'eligible', 1);
        $this->entry($list, 3, $second, 'eligible', 2);
        $this->actingAsAdminWith(['personnel.directory.view']);

        Livewire::test(MasterPersonnelDirectory::class)
            ->set('personnelSortRules', [['field' => 'promotional_rank', 'direction' => 'asc'], ['field' => 'name', 'direction' => 'asc']])
            ->assertCanSeeTableRecords([$first, $second, $none], inOrder: true)
            ->set('personnelSortRules', [['field' => 'promotional_rank', 'direction' => 'desc'], ['field' => 'name', 'direction' => 'asc']])
            ->assertCanSeeTableRecords([$second, $first, $none], inOrder: true)
            ->set('personnelSortRules', [['field' => 'promotional_list', 'direction' => 'asc']])
            ->assertSuccessful();
    }

    public function test_csv_and_xlsx_exports_include_the_promotional_fields(): void
    {
        [$ann, $bo] = [$this->employee('Ann Fixture'), $this->employee('Bo Fixture')];
        $this->entry($this->list('lieutenant', 'Lieutenant Results 2026', '2026-04-01', '2027-04-01'), 2, $ann, 'eligible', 1);
        $this->entry($this->list('captain', 'Captain Results 2025', '2025-04-01', null), 5, $bo, 'eligible', 4);
        $actor = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.export']);
        $table = app(PersonnelTableService::class)->master($actor);
        $columns = ['name', ...self::PROMO];
        $exporter = app(PersonnelTableExporter::class);

        $csv = $this->bytes($exporter->export($actor, $table, new Collection([$ann, $bo]), $columns, 'csv', 'selected'));
        self::assertStringContainsString('"Promotional list","Promotional rank","Promotional status","Promotional list expires"', $csv);
        self::assertStringContainsString('"Ann Fixture","Lieutenant - Lieutenant Results 2026",1,Eligible,"Apr 1, 2027"', $csv);
        self::assertStringContainsString('"Captain - Captain Results 2025"', $csv);

        $path = tempnam(sys_get_temp_dir(), 'promo-dir-');
        try {
            file_put_contents($path, $this->bytes($exporter->export($actor, $table, new Collection([$ann]), $columns, 'xlsx', 'selected')));
            $sheet = IOFactory::load($path)->getSheetByName('Personnel');
            self::assertSame('Promotional rank', $sheet->getCell('C1')->getValue());
            self::assertSame('1', (string) $sheet->getCell('C2')->getValue());
        } finally {
            unlink($path);
        }
        self::assertStringStartsWith('%PDF-', $this->bytes($exporter->export($actor, $table, new Collection([$ann]), $columns, 'pdf', 'selected')));
    }

    public function test_the_directory_does_not_run_a_query_per_member(): void
    {
        $this->actingAsAdminWith(['personnel.directory.view']);
        $list = $this->list('lieutenant', 'Lieutenant Results 2026', '2026-04-01', null);
        $this->populate($list, 5);
        $small = $this->renderQueryCount();

        $this->populate($list, 40, 6);
        $large = $this->renderQueryCount();

        self::assertLessThanOrEqual($small + 2, $large, 'Query count must not grow with the number of ranked members.');
    }

    public function test_choosing_promotional_columns_is_kept_by_saved_views_and_custom_tables(): void
    {
        $actor = $this->actingAsAdminWith(['admin.personnel.view', 'admin.personnel.manage']);
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        $ann = $this->employee('Ann Fixture');
        $this->entry($this->list('lieutenant', 'Lieutenant Results 2026', '2026-04-01', null), 2, $ann, 'eligible', 1);
        $service = app(PersonnelTableService::class);

        Livewire::test(MasterPersonnelDirectory::class)
            ->set('toggledTableColumns.promotional_rank', true)
            ->set('toggledTableColumns.promotional_list', true)
            ->callAction('saveView', data: ['name' => 'Promotion watch'])
            ->assertHasNoActionErrors();
        $view = $service->master($actor)->views()->where('user_id', $actor->id)->firstOrFail();
        self::assertTrue($view->settings['toggledTableColumns']['promotional_rank']);

        $custom = $service->create($actor, ['name' => 'Promotion watch table', 'membership_mode' => 'dynamic',
            'criteria' => ['promotional_on_list' => true], 'source_columns' => ['name', 'promotional_list', 'promotional_rank']]);
        self::assertSame([$ann->id], $service->members($actor, $custom)->pluck('employees.id')->all());

        $this->expectException(ValidationException::class);
        $service->create($actor, ['name' => 'Bad filter', 'membership_mode' => 'dynamic', 'criteria' => ['promotional_list_type' => ['sergeant']], 'source_columns' => ['name']]);
    }

    private function renderQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MasterPersonnelDirectory::class)->set('toggledTableColumns.promotional_list', true)->set('toggledTableColumns.promotional_rank', true)
            ->set('toggledTableColumns.promotional_status', true)->set('toggledTableColumns.promotional_expires', true);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function populate(PromotionalList $list, int $count, int $from = 1): void
    {
        for ($i = $from; $i < $from + $count; $i++) {
            $this->entry($list, $i + 1, $this->employee('Member '.$i.' Fixture'), 'eligible', $i);
        }
    }

    private function list(string $type, string $title, string $effective, ?string $expires, string $status = 'active', ?string $label = null): PromotionalList
    {
        return PromotionalList::query()->create(['list_type' => $type, 'type_label' => $label, 'title' => $title, 'effective_date' => $effective,
            'expires_on' => $expires, 'status' => $status, 'source_reference' => 'Fixture document']);
    }

    private function entry(PromotionalList $list, int $rank, Employee $employee, string $status, ?int $current): PromotionalListEntry
    {
        return $list->entries()->create(['original_rank' => $rank, 'soi_id' => 'S'.$rank.$employee->id, 'last_name' => 'Fixture', 'first_name' => 'Pat',
            'employee_id' => $employee->id, 'scores' => [], 'status' => $status, 'current_rank' => $current]);
    }

    private function employee(string $name): Employee
    {
        static $sequence = 0;
        $sequence++;

        return Employee::query()->create(['employee_id' => sprintf('D%04d', $sequence), 'name' => $name, 'rank' => 'Firefighter', 'roster_status' => 'active', 'station' => '1']);
    }

    private function bytes(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
