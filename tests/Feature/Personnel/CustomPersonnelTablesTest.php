<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\CustomPersonnelTables;
use App\Filament\Pages\PersonnelTableEditor;
use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\User;
use App\Services\Personnel\PersonnelTableService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CustomPersonnelTablesTest extends TestCase
{
    use RefreshDatabase;

    private \Closure $restoreRepeaterUuids;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.employee_bootstrap.secret' => 'test-only-bootstrap']);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->restoreRepeaterUuids = Repeater::fake();
    }

    protected function tearDown(): void
    {
        ($this->restoreRepeaterUuids)();
        parent::tearDown();
    }

    public function test_create_table_selects_current_personnel_without_creating_accounts(): void
    {
        $this->loginManager();
        $current = $this->employee('UI-001', 'Current member');
        $this->employee('UI-002', 'Archived member', 'inactive');
        $accounts = User::query()->count();
        Livewire::test(CustomPersonnelTables::class)->mountAction('createTable')
            ->set('mountedActionsData.0.source_columns', [])->setActionData([
                'name' => 'Equipment acknowledgment', 'selection_mode' => 'all', 'membership_mode' => 'fixed',
                'source_columns' => [['field' => 'name'], ['field' => 'rank']],
                'columns' => [['id' => (string) Str::uuid(), 'label' => 'Completed', 'type' => 'checkbox', 'hidden' => false]],
                'visibility' => 'private',
            ])->callMountedAction()->assertHasNoActionErrors()->assertRedirect();
        $table = PersonnelTable::query()->where('name', 'Equipment acknowledgment')->sole();
        self::assertSame([$current->id], $table->entries()->pluck('employee_id')->all());
        self::assertSame(['name', 'rank'], $table->source_columns);
        self::assertSame($accounts, User::query()->count());
    }

    public function test_manual_and_blank_creation_do_not_expand_into_all_members(): void
    {
        $this->loginManager();
        $one = $this->employee('UI-003', 'Selected member');
        $this->employee('UI-004', 'Other member');
        foreach (['manual' => [$one->id], 'blank' => []] as $mode => $ids) {
            Livewire::test(CustomPersonnelTables::class)->mountAction('createTable')
                ->set('mountedActionsData.0.source_columns', [])->setActionData([
                    'name' => $mode, 'selection_mode' => $mode, 'employee_ids' => $ids,
                    'source_columns' => [['field' => 'name']], 'columns' => [], 'visibility' => 'private',
                ])->callMountedAction()->assertHasNoActionErrors();
            self::assertSame($ids, PersonnelTable::query()->where('name', $mode)->sole()->entries()->pluck('employee_id')->all());
        }
    }

    public function test_checkbox_filter_distinguishes_checked_unchecked_and_empty_values(): void
    {
        $owner = $this->loginManager();
        $checked = $this->employee('UI-005', 'Checked member');
        $unchecked = $this->employee('UI-006', 'Unchecked member');
        $table = $this->makeTable($owner, [$checked->id, $unchecked->id]);
        $id = $table->columns[0]['id'];
        $component = Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])
            ->assertCanSeeTableRecords([$checked, $unchecked])
            ->call('updateTableColumnState', 'tracking_'.$id, (string) $checked->id, true)
            ->assertHasNoErrors();
        self::assertTrue($table->entries()->where('employee_id', $checked->id)->sole()->values[$id]);
        $component->filterTable('tracking_'.$id, ['value' => 'checked'])
            ->assertCanSeeTableRecords([$checked])->assertCanNotSeeTableRecords([$unchecked])
            ->filterTable('tracking_'.$id, ['value' => 'unchecked'])
            ->assertCanSeeTableRecords([$unchecked])->assertCanNotSeeTableRecords([$checked]);
    }

    public function test_stale_checkbox_edit_is_rejected_without_overwriting_another_admin(): void
    {
        $owner = $this->loginManager();
        $member = $this->employee('UI-007', 'Concurrent member');
        $table = $this->makeTable($owner, [$member->id]);
        $id = $table->columns[0]['id'];
        $component = Livewire::test(PersonnelTableEditor::class, ['table' => $table->id]);
        app(PersonnelTableService::class)->setCell($owner, $table, $member, $id, true, 0);
        $component->call('updateTableColumnState', 'tracking_'.$id, (string) $member->id, false)->assertHasErrors(['conflict']);
        self::assertTrue($table->entries()->sole()->values[$id]);
    }

    public function test_notes_highlight_and_membership_changes_preserve_personnel_and_tracking(): void
    {
        $owner = $this->loginManager();
        $member = $this->employee('UI-008', 'Retained member');
        $before = $member->fresh()->getRawOriginal();
        $table = $this->makeTable($owner, [$member->id], [['label' => 'Notes', 'type' => 'notes']]);
        $id = $table->columns[0]['id'];
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])
            ->callTableAction('editTracking', $member, ['values' => [$id => 'Follow up next week'], 'highlight' => 'warning', 'entry_version' => 0])
            ->assertHasNoTableActionErrors()
            ->callTableBulkAction('removeMembers', [$member])->assertHasNoTableActionErrors();
        $entry = $table->entries()->sole();
        self::assertSame('Follow up next week', $entry->values[$id]);
        self::assertSame('warning', $entry->highlight);
        self::assertNotNull($entry->removed_at);
        self::assertSame($before, $member->fresh()->getRawOriginal());
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->callAction('members', ['employee_ids' => [$member->id]])->assertHasNoActionErrors()
            ->assertCanSeeTableRecords([$member]);
        self::assertSame('Follow up next week', $entry->fresh()->values[$id]);
    }

    public function test_named_view_reopens_search_sort_and_column_choices(): void
    {
        $owner = $this->loginManager();
        $member = $this->employee('UI-009', 'Saved member');
        $table = $this->makeTable($owner, [$member->id]);
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->searchTable('Saved')
            ->set('personnelSortRules', [['field' => 'rank', 'direction' => 'desc'], ['field' => 'name', 'direction' => 'asc']])
            ->callAction('saveView', ['name' => 'Follow up'])->assertHasNoActionErrors();
        $view = $table->views()->sole();
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->searchTable('Absent')
            ->callAction('applyView', ['view_id' => $view->id])->assertHasNoActionErrors()
            ->assertSet('tableSearch', 'Saved')->assertSet('personnelSortRules', $view->settings['personnelSortRules'])
            ->assertCanSeeTableRecords([$member]);
    }

    public function test_archive_delete_and_restore_are_available_without_deleting_personnel(): void
    {
        $owner = $this->loginManager();
        $member = $this->employee('UI-010', 'Preserved member');
        $table = $this->makeTable($owner, [$member->id]);
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->assertActionHidden('deleteTable')
            ->callAction('archive')->assertHasNoActionErrors();
        self::assertNotNull($table->fresh()->archived_at);
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->assertActionVisible('restore')
            ->assertActionVisible('deleteTable')->callAction('deleteTable')->assertHasNoActionErrors();
        self::assertTrue(PersonnelTable::withTrashed()->findOrFail($table->id)->trashed());
        $deleted = PersonnelTable::withTrashed()->findOrFail($table->id);
        Livewire::test(CustomPersonnelTables::class)->filterTable('state', ['value' => 'deleted'])
            ->assertTableActionVisible('restore', $deleted)->callTableAction('restore', $deleted)->assertHasNoTableActionErrors();
        self::assertFalse($table->fresh()->trashed());
        self::assertNull($table->fresh()->archived_at);
        self::assertTrue(Employee::query()->whereKey($member->id)->exists());
    }

    public function test_other_manager_cannot_open_private_table_by_id(): void
    {
        $owner = $this->loginManager();
        $table = $this->makeTable($owner);
        $this->loginManager();
        Livewire::test(CustomPersonnelTables::class)->assertCanNotSeeTableRecords([$table]);
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->assertForbidden();
    }

    public function test_viewer_cannot_force_tracking_edit_or_create_table(): void
    {
        $owner = $this->loginManager();
        $member = $this->employee('UI-011', 'Read only member');
        $table = $this->makeTable($owner, [$member->id]);
        $table = app(PersonnelTableService::class)->update($owner, $table, ['visibility' => 'shared'], $table->version);
        $viewer = User::factory()->create(['account_status' => 'active']);
        foreach (['admin.access', 'personnel.tables.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($viewer);
        Livewire::test(CustomPersonnelTables::class)->assertActionHidden('createTable');
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->assertActionHidden('configureColumns')
            ->assertTableActionHidden('editTracking', $member)
            ->call('updateTableColumnState', 'tracking_'.$table->columns[0]['id'], (string) $member->id, true);
        self::assertSame([], $table->entries()->sole()->values);
    }

    public function test_table_only_viewer_can_open_table_with_restricted_email_quality_metadata(): void
    {
        $owner = $this->loginManager();
        $member = $this->employee('UI-QUALITY', 'Quality metadata member');
        $table = $this->makeTable($owner, [$member->id]);
        $table = app(PersonnelTableService::class)->update($owner, $table,
            ['visibility' => 'shared', 'source_columns' => ['name', 'email_match_status']], $table->version);
        $viewer = User::factory()->create(['account_status' => 'active']);
        foreach (['admin.access', 'personnel.tables.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($viewer);
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->assertOk()
            ->assertCanSeeTableRecords([$member])
            ->assertTableColumnStateSet('email_match_status', null, $member)
            ->assertActionHidden('export')->callTableAction('details', $member)
            ->assertSee('Not recorded');
    }

    public function test_export_actions_use_filtered_all_and_selected_member_scopes(): void
    {
        $owner = $this->loginManager();
        $one = $this->employee('UI-EXPORT-ONE', 'First export member');
        $two = $this->employee('UI-EXPORT-TWO', 'Second export member');
        $table = $this->makeTable($owner, [$one->id, $two->id]);
        $component = Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->searchTable('First')
            ->callAction('export', ['format' => 'csv', 'scope' => 'filtered'])->assertHasNoActionErrors()->assertFileDownloaded();
        $filtered = base64_decode($component->effects['download']['content']);
        self::assertStringContainsString($one->employee_id, $filtered);
        self::assertStringNotContainsString($two->employee_id, $filtered);
        $component->callAction('export', ['format' => 'csv', 'scope' => 'all'])->assertHasNoActionErrors()->assertFileDownloaded();
        $all = base64_decode($component->effects['download']['content']);
        self::assertStringContainsString($one->employee_id, $all);
        self::assertStringContainsString($two->employee_id, $all);
        $component->callTableBulkAction('exportSelected', [$one], ['format' => 'csv'])->assertHasNoTableActionErrors()->assertFileDownloaded();
        $selected = base64_decode($component->effects['download']['content']);
        self::assertStringContainsString($one->employee_id, $selected);
        self::assertStringNotContainsString($two->employee_id, $selected);
    }

    public function test_dynamic_create_and_member_filters_use_live_personnel_fields(): void
    {
        $owner = $this->loginManager();
        $captain = $this->employee('UI-012', 'Selected captain');
        $other = $this->employee('UI-013', 'Other rank');
        $other->update(['rank' => 'Lieutenant']);
        Livewire::test(CustomPersonnelTables::class)->mountAction('createTable')
            ->set('mountedActionsData.0.source_columns', [])->setActionData([
                'name' => 'Captains', 'selection_mode' => 'filter', 'membership_mode' => 'dynamic',
                'criteria' => ['active_only' => true, 'rank' => ['Captain']],
                'source_columns' => [['field' => 'name']], 'columns' => [], 'visibility' => 'private',
            ])->callMountedAction()->assertHasNoActionErrors();
        $table = PersonnelTable::query()->where('name', 'Captains')->sole();
        self::assertSame('dynamic', $table->membership_mode);
        Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])
            ->assertCanSeeTableRecords([$captain])->assertCanNotSeeTableRecords([$other])
            ->callAction('members', ['criteria' => ['active_only' => true, 'rank' => ['Lieutenant']]])->assertHasNoActionErrors()
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$captain]);
    }

    public function test_column_reordering_removal_and_recovery_keep_values_attached_to_member(): void
    {
        $owner = $this->loginManager();
        $member = $this->employee('UI-014', 'Column recovery member');
        $table = $this->makeTable($owner, [$member->id]);
        $column = $table->columns[0];
        app(PersonnelTableService::class)->setCell($owner, $table, $member, $column['id'], true, 0);
        $component = Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])
            ->mountAction('configureColumns')->set('mountedActionsData.0.source_columns', [])
            ->setActionData(['source_columns' => [['field' => 'rank'], ['field' => 'name']], 'columns' => []])
            ->callMountedAction()->assertHasNoActionErrors();
        self::assertSame(['rank', 'name'], $table->fresh()->source_columns);
        self::assertSame($column['id'], $table->fresh()->retired_columns[0]['id']);
        $component->callAction('restoreColumn', ['column_id' => $column['id']])->assertHasNoActionErrors()
            ->assertTableColumnExists('tracking_'.$column['id']);
        self::assertTrue($table->entries()->sole()->values[$column['id']]);
    }

    public function test_numeric_tracking_sort_and_conditional_highlight_follow_typed_values(): void
    {
        $owner = $this->loginManager();
        $ten = $this->employee('UI-015', 'Ten');
        $two = $this->employee('UI-016', 'Two');
        $table = $this->makeTable($owner, [$ten->id, $two->id], [['label' => 'Quantity', 'type' => 'number',
            'color' => 'warning', 'highlight_when' => 'equals', 'highlight_value' => '10']]);
        $id = $table->columns[0]['id'];
        app(PersonnelTableService::class)->setCell($owner, $table, $ten, $id, 10, 0);
        app(PersonnelTableService::class)->setCell($owner, $table, $two, $id, 2, 0);
        $component = Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])
            ->sortTable('tracking_'.$id, 'asc')->assertCanSeeTableRecords([$two, $ten], inOrder: true);
        $column = $component->instance()->getTable()->getColumn('tracking_'.$id);
        self::assertSame('personnel-cell-highlight-warning', $column->record($ten)->getExtraCellAttributes()['class']);
        self::assertSame([], $column->record($two)->getExtraCellAttributes());
        $component->callAction('multiSort', ['sorts' => [['field' => $id, 'direction' => 'desc']]])
            ->assertHasNoActionErrors()->assertCanSeeTableRecords([$ten, $two], inOrder: true)
            ->callAction('saveView', ['name' => 'By quantity'])->assertHasNoActionErrors();
    }

    public function test_rendering_more_members_does_not_add_queries_per_tracking_cell(): void
    {
        $owner = $this->loginManager();
        $one = $this->employee('UI-017', 'Small table member');
        $small = $this->makeTable($owner, [$one->id]);
        $ids = [];
        for ($index = 0; $index < 30; $index++) {
            $ids[] = $this->employee('UI-PERF-'.$index, 'Performance member '.$index)->id;
        }
        $large = $this->makeTable($owner, $ids);
        DB::enableQueryLog();
        Livewire::test(PersonnelTableEditor::class, ['table' => $small->id])->assertCanSeeTableRecords([$one]);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        Livewire::test(PersonnelTableEditor::class, ['table' => $large->id])->assertCountTableRecords(30);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual($smallCount + 15, $largeCount, 'Rendering rows must reuse entry and permission reads rather than query each cell.');
    }

    private function loginManager(): User
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['admin.access', 'admin.personnel.view', 'admin.personnel.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);

        return $user;
    }

    private function employee(string $id, string $name, string $status = 'active'): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => $name, 'rank' => 'Captain', 'roster_status' => $status]);
    }

    private function makeTable(User $owner, array $ids = [], array $columns = [['label' => 'Completed', 'type' => 'checkbox']]): PersonnelTable
    {
        return app(PersonnelTableService::class)->create($owner, ['name' => 'UI table', 'employee_ids' => $ids, 'columns' => $columns]);
    }
}
