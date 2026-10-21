<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\PersonnelTableEditor;
use App\Models\Employee;
use App\Services\Personnel\PersonnelTableService;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

final class CustomPersonnelTableRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private \Closure $restoreRepeaterUuids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        $this->restoreRepeaterUuids = Repeater::fake();
    }

    protected function tearDown(): void
    {
        ($this->restoreRepeaterUuids)();
        parent::tearDown();
    }

    public function test_removing_a_sorted_tracking_column_keeps_the_table_and_old_saved_view_usable(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $member = Employee::query()->create(['employee_id' => '00425', 'name' => 'Recovery Fixture', 'roster_status' => 'active']);
        $service = app(PersonnelTableService::class);
        $columnId = (string) Str::uuid();
        $table = $service->create($actor, ['name' => 'Sort recovery', 'employee_ids' => [$member->id],
            'source_columns' => ['name'], 'columns' => [['id' => $columnId, 'label' => 'Due date', 'type' => 'date']]]);
        $service->setCell($actor, $table, $member, $columnId, '2026-10-21', 0);
        $page = Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])
            ->callAction('multiSort', ['sorts' => [['field' => $columnId, 'direction' => 'asc']]])
            ->assertHasNoActionErrors()->callAction('saveView', ['name' => 'By due date'])->assertHasNoActionErrors();
        $view = $table->views()->where('name', 'By due date')->sole();

        $page->mountAction('configureColumns')->set('mountedActionsData.0.source_columns', [])
            ->setActionData(['source_columns' => [['field' => 'name']], 'columns' => []])
            ->callMountedAction()->assertHasNoActionErrors()->assertCanSeeTableRecords([$member])
            ->callAction('applyView', ['view_id' => $view->id])->assertHasNoActionErrors()
            ->assertCanSeeTableRecords([$member]);

        self::assertSame([['field' => 'name', 'direction' => 'asc']], $page->get('personnelSortRules'));
        self::assertSame($columnId, $table->fresh()->retired_columns[0]['id']);
        self::assertSame('2026-10-21', $table->entries()->sole()->values[$columnId]);
    }

    public function test_shield_role_creation_can_enumerate_the_unmounted_editor_without_a_table_id(): void
    {
        $this->actingAsSuperAdmin();

        self::assertSame('Personnel Table', FilamentShield::getLocalizedPageLabel(PersonnelTableEditor::class));
        $this->get('/admin/shield/roles/create')->assertOk()->assertSee('Personnel Table');
    }
}
