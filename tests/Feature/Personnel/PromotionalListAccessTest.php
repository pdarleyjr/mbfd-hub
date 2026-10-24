<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Resources\PromotionalListResource;
use App\Filament\Resources\PromotionalListResource\Pages\CreatePromotionalList;
use App\Filament\Resources\PromotionalListResource\Pages\ListPromotionalLists;
use App\Filament\Resources\PromotionalListResource\Pages\ViewPromotionalList;
use App\Filament\Resources\PromotionalListResource\RelationManagers\EntriesRelationManager;
use App\Models\Employee;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Models\PromotionalListEvent;
use App\Models\User;
use App\Services\Personnel\Promotions\PromotionalListExporter;
use App\Services\Personnel\Promotions\PromotionalListService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** Who may see, change and export promotional lists, and the admin screens that use those rules. */
final class PromotionalListAccessTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    public function test_the_manage_permission_exists_after_migration(): void
    {
        self::assertTrue(Permission::query()->where('name', 'personnel.promotions.manage')->where('guard_name', 'web')->exists());
    }

    public function test_a_directory_viewer_can_read_but_never_change_a_list(): void
    {
        [$list, $entry] = $this->seedList();
        $viewer = $this->actingAsAdminWith(['personnel.directory.view']);
        $service = app(PromotionalListService::class);

        $this->get('/admin/promotional-lists')->assertOk()->assertSee('Fixture Lieutenant Results');
        $this->get('/admin/promotional-lists/'.$list->id)->assertOk();
        self::assertTrue(PromotionalListResource::canViewAny());
        self::assertFalse(PromotionalListResource::canCreate());
        self::assertFalse(PromotionalListResource::canEdit($list));
        $this->get('/admin/promotional-lists/create')->assertForbidden();

        foreach ([
            fn () => $service->createList($viewer, ['list_type' => 'captain', 'title' => 'X', 'effective_date' => '2026-01-01', 'source_reference' => 'Y']),
            fn () => $service->addEntry($viewer, $list, ['original_rank' => 9, 'last_name' => 'A', 'first_name' => 'B']),
            fn () => $service->changeEntryStatus($viewer, $entry, 'promoted', 'Promoted 2026-11-01'),
            fn () => $service->changeListStatus($viewer, $list, 'expired', 'Done'),
            fn () => $service->linkEmployee($viewer, $entry, null),
            fn () => $service->updateEntry($viewer, $entry, ['last_name' => 'Changed']),
            fn () => $service->updateList($viewer, $list, ['title' => 'Changed']),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('A viewer must not change promotional lists.');
            } catch (AuthorizationException) {
                // expected
            }
        }
        self::assertSame('eligible', $entry->fresh()->status);
        self::assertSame('Fixture Lieutenant Results 2026', $list->fresh()->title);
    }

    public function test_the_manage_permission_alone_is_not_enough_without_directory_view(): void
    {
        $actor = $this->actingAsAdminWith(['personnel.promotions.manage']);

        self::assertFalse(PromotionalListResource::canViewAny());
        $this->get('/admin/promotional-lists')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(PromotionalListService::class)->createList($actor, ['list_type' => 'captain', 'title' => 'X', 'effective_date' => '2026-01-01', 'source_reference' => 'Y']);
    }

    public function test_members_without_directory_access_and_uniform_managers_are_turned_away(): void
    {
        $this->seedList();
        $this->actingAs(User::factory()->create(['account_status' => 'active']));
        self::assertNotSame(200, $this->get('/admin/promotional-lists')->getStatusCode());

        // The uniform and PPE manager set is not a personnel manager and may not read promotional rankings.
        $this->actingAsAdminWith(['admin.personnel.view', 'admin.personnel.manage']);
        self::assertFalse(PromotionalListResource::canViewAny());
        self::assertNotSame(200, $this->get('/admin/promotional-lists')->getStatusCode());
    }

    public function test_revoking_the_permission_takes_effect_for_a_stale_session(): void
    {
        [, $entry] = $this->seedList();
        $manager = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.promotions.manage']);
        $manager->revokePermissionTo('personnel.promotions.manage');

        $this->expectException(AuthorizationException::class);
        app(PromotionalListService::class)->changeEntryStatus($manager, $entry, 'removed', 'Resigned');
    }

    public function test_a_manager_can_change_everything_through_the_entries_screen_and_every_change_is_logged(): void
    {
        [$list, $entry] = $this->seedList();
        $manager = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.promotions.manage']);
        $exact = Employee::query()->create(['employee_id' => 'Q0001', 'name' => 'Pat Fixture2', 'rank' => 'Firefighter', 'roster_status' => 'active']);
        Employee::query()->create(['employee_id' => 'Q0002', 'name' => 'Pat Fixture2', 'rank' => 'Firefighter', 'roster_status' => 'retired']);

        $screen = $this->entries($list)
            ->assertCanSeeTableRecords([$entry])
            ->assertTableActionVisible('changeStatus', $entry)
            ->callTableAction('changeStatus', $entry, data: ['status' => 'promoted', 'reason' => 'Promoted 2026-11-01'])
            ->assertHasNoTableActionErrors();
        self::assertSame('promoted', $entry->fresh()->status);
        self::assertNull($entry->fresh()->current_rank);

        $this->entries($list)->callTableAction('changeStatus', $entry, data: ['status' => 'promoted', 'reason' => 'Again']);
        self::assertSame(1, PromotionalListEvent::query()->where('action', 'entry_status_changed')->count(), 'A repeated change is refused and not logged.');

        $this->entries($list)->callTableAction('link', $entry, data: ['employee_id' => $exact->id]);
        self::assertSame($exact->id, $entry->fresh()->employee_id);

        $this->entries($list)->callTableAction('editEntry', $entry, data: ['original_rank' => 2, 'soi_id' => 'L2', 'last_name' => 'Fixture2', 'first_name' => 'Pat',
            'scores' => ['written' => '88.50', 'oral' => 'P', 'composite' => '', 'seniority' => '', 'education' => '', 'max_promotional' => '', 'veteran' => '', 'total' => '88.50']]);
        self::assertSame('88.50', $entry->fresh()->scores['total']);
        self::assertSame($manager->id, PromotionalListEvent::query()->where('action', 'entry_linked')->firstOrFail()->actor_user_id);
        self::assertGreaterThanOrEqual(1, PromotionalListEvent::query()->where('action', 'entry_updated')->count());
    }

    public function test_the_entries_screen_hides_change_actions_from_a_viewer(): void
    {
        [$list, $entry] = $this->seedList();
        $this->actingAsAdminWith(['personnel.directory.view']);

        Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $list, 'pageClass' => ViewPromotionalList::class])
            ->assertCanSeeTableRecords([$entry])
            ->assertTableActionHidden('changeStatus', $entry)
            ->assertTableActionHidden('link', $entry)
            ->assertTableActionHidden('editEntry', $entry);
    }

    public function test_a_viewer_cannot_forge_a_change_by_calling_a_hidden_action(): void
    {
        [$list, $entry] = $this->seedList();
        $this->actingAsAdminWith(['personnel.directory.view']);

        $this->entries($list)->call('mountTableAction', 'changeStatus', $entry->getKey())
            ->call('callMountedTableAction', ['status' => 'removed', 'reason' => 'Forged']);

        self::assertSame('eligible', $entry->fresh()->status);
    }

    public function test_creating_a_list_from_the_form_goes_through_the_service(): void
    {
        $manager = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.promotions.manage']);

        Livewire::test(CreatePromotionalList::class)
            ->fillForm(['list_type' => 'captain', 'title' => 'Fixture Captain Results 2026', 'effective_date' => '2026-06-01', 'status' => 'draft', 'source_reference' => 'Fixture document'])
            ->call('create')->assertHasNoFormErrors();

        $list = PromotionalList::query()->where('title', 'Fixture Captain Results 2026')->firstOrFail();
        self::assertSame($manager->id, $list->created_by);
        self::assertSame('draft', $list->status);

        Livewire::test(CreatePromotionalList::class)
            ->fillForm(['list_type' => 'captain', 'title' => 'Duplicate', 'effective_date' => '2026-06-01', 'status' => 'draft', 'source_reference' => 'Fixture document'])
            ->call('create')->assertHasFormErrors(['effective_date']);
        self::assertSame(1, PromotionalList::query()->count());
    }

    public function test_the_list_page_shows_type_dates_status_and_eligible_count(): void
    {
        [$list] = $this->seedList();
        $this->actingAsAdminWith(['personnel.directory.view']);

        $screen = Livewire::test(ListPromotionalLists::class)->assertCanSeeTableRecords([$list])
            ->assertTableColumnStateSet('status', 'Active', $list);
        self::assertSame(1, (int) $screen->instance()->getTableRecords()->first()->eligible_entries_count);
    }

    public function test_export_follows_directory_export_rules_is_typed_and_is_logged(): void
    {
        [$list, $entry] = $this->seedList();
        $entry->update(['last_name' => '=1+1', 'scores' => ['written' => '82.00', 'oral' => 'p', 'education' => '-', 'total' => '108.00']]);
        $exporter = app(PromotionalListExporter::class);

        $viewer = $this->actingAsAdminWith(['personnel.directory.view']);
        try {
            $exporter->export($viewer, $list, 'xlsx');
            self::fail('A viewer without export permission must be refused.');
        } catch (AuthorizationException) {
            // expected
        }

        $exporterUser = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.directory.export']);
        $bytes = $this->bytes($exporter->export($exporterUser, $list, 'xlsx'));
        $path = tempnam(sys_get_temp_dir(), 'promo-x-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Promotional list');
            self::assertSame('Current rank', $sheet->getCell('A1')->getValue());
            self::assertSame(1, (int) $sheet->getCell('A2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
            self::assertSame('=1+1', $sheet->getCell('D2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('D2')->getDataType(), 'Names are text, never formulas.');
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('I2')->getDataType(), 'A numeric score is a number.');
            self::assertSame('p', $sheet->getCell('J2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('J2')->getDataType());
            self::assertSame('-', $sheet->getCell('M2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('M2')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $pdf = $this->bytes($exporter->export($exporterUser, $list, 'pdf'));
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, PromotionalListEvent::query()->where('action', 'exported')->count());
    }

    private function entries(PromotionalList $list): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $list, 'pageClass' => ViewPromotionalList::class]);
    }

    /** @return array{0: PromotionalList, 1: PromotionalListEntry} */
    private function seedList(): array
    {
        $list = PromotionalList::query()->create(['list_type' => 'lieutenant', 'title' => 'Fixture Lieutenant Results 2026', 'effective_date' => '2026-04-01',
            'status' => 'active', 'source_reference' => 'Fixture results document']);
        $entry = $list->entries()->create(['original_rank' => 2, 'soi_id' => 'L2', 'last_name' => 'Fixture2', 'first_name' => 'Pat',
            'scores' => ['total' => '90.00'], 'status' => 'eligible', 'current_rank' => 1]);

        return [$list, $entry];
    }

    private function bytes(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
