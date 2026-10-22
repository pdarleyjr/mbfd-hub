<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\PersonnelTable;
use App\Models\PersonnelTableEvent;
use App\Models\User;
use App\Services\Personnel\PersonnelSource;
use App\Services\Personnel\PersonnelTableService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PersonnelTableServiceTest extends TestCase
{
    use RefreshDatabase;

    private PersonnelTableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.employee_bootstrap.secret' => 'test-only-bootstrap']);
        $this->service = app(PersonnelTableService::class);
    }

    public function test_tracking_changes_do_not_modify_personnel_accounts_or_history(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00701');
        $before = $employee->refresh()->getRawOriginal();
        $table = $this->table($owner, [$employee->id]);
        $column = $table->columns[0]['id'];
        $entry = $this->service->setCell($owner, $table, $employee, $column, true, 0);
        self::assertTrue($entry->values[$column]);
        self::assertSame(1, $entry->version);
        self::assertSame($before, $employee->fresh()->getRawOriginal());
        self::assertSame(0, User::query()->where('employee_profile_id', $employee->id)->count());
        $this->service->syncMembers($owner, $table, [], $table->version);
        self::assertSame(0, $this->service->members($owner, $table)->count());
        self::assertTrue($entry->fresh()->values[$column]);
        $table = $table->fresh();
        $this->service->syncMembers($owner, $table, [$employee->id], $table->version);
        self::assertTrue($entry->fresh()->values[$column]);
    }

    public function test_private_tables_cannot_be_read_or_mutated_by_another_manager(): void
    {
        $owner = $this->manager();
        $other = $this->manager();
        $employee = $this->employee('00702');
        $table = $this->table($owner, [$employee->id]);
        self::assertFalse($this->service->canView($other, $table));
        self::assertSame(0, $this->service->accessibleQuery($other)->count());
        $this->expectException(AuthorizationException::class);
        $this->service->setCell($other, $table, $employee, $table->columns[0]['id'], true, 0);
    }

    public function test_fresh_permissions_and_disabled_account_reject_stale_actors(): void
    {
        $owner = $this->manager();
        $table = $this->table($owner);
        $owner->load('permissions');
        DB::table('model_has_permissions')->where('model_id', $owner->id)->delete();
        self::assertFalse($this->service->canManage($owner, $table));
        $owner = $owner->fresh();
        foreach (['admin.access', 'admin.personnel.manage'] as $permission) {
            $owner->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        self::assertTrue($this->service->canManage($owner, $table));
        DB::table('users')->where('id', $owner->id)->update(['account_status' => 'disabled']);
        self::assertFalse($this->service->canManage($owner, $table));
    }

    public function test_view_and_export_permissions_are_independent(): void
    {
        $viewer = User::factory()->create(['account_status' => 'active']);
        foreach (['admin.access', 'personnel.directory.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $master = $this->service->master($viewer);
        self::assertTrue($this->service->canView($viewer, $master));
        self::assertFalse($this->service->canManage($viewer, $master));
        $this->expectException(AuthorizationException::class);
        $this->service->authorize($viewer, $master, 'export');
    }

    public function test_module_permissions_do_not_replace_current_admin_admission(): void
    {
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->givePermissionTo(Permission::findOrCreate('personnel.directory.view', 'web'));
        self::assertFalse($this->service->canViewDirectory($actor));
        $actor->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        self::assertTrue($this->service->canViewDirectory($actor));
        DB::table('model_has_permissions')->where('model_id', $actor->id)
            ->where('permission_id', Permission::findByName('admin.access', 'web')->id)->delete();
        self::assertFalse($this->service->canViewDirectory($actor));
    }

    public function test_copying_data_and_sharing_require_their_explicit_permissions(): void
    {
        $actor = User::factory()->create(['account_status' => 'active']);
        foreach (['admin.access', 'personnel.tables.view', 'personnel.tables.create', 'personnel.tables.edit'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $table = $this->table($actor);
        self::assertNotNull($this->service->duplicate($actor, $table, false));
        try {
            $this->service->duplicate($actor, $table, true);
            self::fail('Copying data needs export authorization.');
        } catch (AuthorizationException) {
            self::assertSame(2, PersonnelTable::query()->count());
        }
        $this->expectException(AuthorizationException::class);
        $this->service->update($actor, $table, ['visibility' => 'shared'], $table->version);
    }

    public function test_saved_views_reject_malformed_component_state(): void
    {
        $actor = $this->manager();
        $table = $this->table($actor);
        foreach ([['tableSearch' => ['bad']], ['tableFilters' => ['rank' => 'bad']], ['toggledTableColumns' => ['name' => '<script>']], ['personnelSortRules' => [['field' => 'password', 'direction' => 'asc']]], ['arbitrary_action' => 'delete']] as $settings) {
            try {
                $this->service->saveView($actor, $table, 'Invalid view', $settings);
                self::fail('Malformed component state must be rejected.');
            } catch (ValidationException) {
                self::assertSame(0, $table->views()->count());
            }
        }
    }

    public function test_column_and_cell_payloads_are_validated_and_cross_table_ids_rejected(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00703');
        $table = $this->table($owner, [$employee->id]);
        try {
            $this->service->setCell($owner, $table, $employee, $table->columns[0]['id'], '<script>', 0);
            self::fail('A checkbox must reject text.');
        } catch (ValidationException) {
            self::assertSame([], $table->entries()->sole()->values);
        }
        $other = $this->table($owner, [$employee->id]);
        $this->expectException(ValidationException::class);
        $this->service->setCell($owner, $table, $employee, $other->columns[0]['id'], true, 0);
    }

    public function test_concurrent_cell_or_configuration_writes_do_not_silently_overwrite(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00704');
        $table = $this->table($owner, [$employee->id]);
        $column = $table->columns[0]['id'];
        $this->service->setCell($owner, $table, $employee, $column, true, 0);
        try {
            $this->service->setCell($owner, $table, $employee, $column, false, 0);
            self::fail('Stale cell save must conflict.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('conflict', $exception->errors());
        }
        $this->service->update($owner, $table, ['name' => 'New title'], $table->version);
        $this->expectException(ValidationException::class);
        $this->service->update($owner, $table, ['name' => 'Stale title'], $table->version);
    }

    public function test_column_removal_archival_soft_deletion_and_restore_preserve_tracking(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00705');
        $table = $this->table($owner, [$employee->id]);
        $column = $table->columns[0];
        $entry = $this->service->setCell($owner, $table, $employee, $column['id'], true, 0);
        $table = $this->service->update($owner, $table, ['columns' => []], $table->version);
        self::assertCount(1, $table->retired_columns);
        self::assertTrue($entry->fresh()->values[$column['id']]);
        $table = $this->service->archive($owner, $table, $table->version);
        $table = $this->service->delete($owner, $table, $table->version);
        self::assertTrue($table->trashed());
        self::assertSame(1, Employee::query()->count());
        $table = $this->service->restore($owner, $table, $table->version);
        self::assertFalse($table->trashed());
        self::assertNull($table->archived_at);
        $table = $this->service->update($owner, $table, ['columns' => [$column]], $table->version);
        self::assertSame([], $table->retired_columns);
        self::assertTrue($entry->fresh()->values[$column['id']]);
    }

    public function test_dynamic_membership_retains_values_after_a_member_stops_matching(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00706');
        $table = $this->service->create($owner, [
            'name' => 'Captains', 'membership_mode' => 'dynamic', 'criteria' => ['rank' => 'Captain'],
            'columns' => [['label' => 'Done', 'type' => 'checkbox']],
        ]);
        $column = $table->columns[0]['id'];
        $entry = $this->service->setCell($owner, $table, $employee, $column, true, 0);
        $employee->update(['rank' => 'Lieutenant']);
        self::assertSame(0, $this->service->members($owner, $table)->count());
        self::assertTrue($entry->fresh()->values[$column]);
        $employee->update(['rank' => 'Captain']);
        self::assertSame(1, $this->service->members($owner, $table)->count());
        self::assertTrue($entry->fresh()->values[$column]);
    }

    public function test_structure_duplication_does_not_copy_values_without_an_explicit_choice(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00707');
        $table = $this->table($owner, [$employee->id]);
        $this->service->setCell($owner, $table, $employee, $table->columns[0]['id'], true, 0);
        $empty = $this->service->duplicate($owner, $table, false);
        self::assertSame([], $empty->entries()->sole()->values);
        $withData = $this->service->duplicate($owner, $table, true);
        self::assertTrue($withData->entries()->sole()->values[$withData->columns[0]['id']]);
        self::assertNotSame($table->columns[0]['id'], $withData->columns[0]['id']);
    }

    public function test_saved_views_are_owned_bounded_and_audits_do_not_contain_cell_values(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00708');
        $table = $this->service->create($owner, ['name' => 'Notes', 'employee_ids' => [$employee->id], 'columns' => [['label' => 'Notes', 'type' => 'notes']]]);
        $this->service->setCell($owner, $table, $employee, $table->columns[0]['id'], 'Sensitive tracking remark', 0);
        $view = $this->service->saveView($owner, $table, 'Pending', ['search' => 'Captain', 'sort' => 'name', 'direction' => 'asc']);
        self::assertSame($owner->id, $view->user_id);
        self::assertStringNotContainsString('Sensitive tracking remark', PersonnelTableEvent::query()->get()->toJson());
        $this->service->deleteView($owner, $table, $view->id);
        self::assertSame(0, $table->views()->count());
    }

    public function test_source_fields_use_only_the_latest_published_non_future_bid_term(): void
    {
        $this->travelTo(now('America/New_York')->setDate(2026, 10, 21));
        $current = $this->employee('00709');
        $olderOnly = $this->employee('00710');
        $this->bid($current, 2025, 'A Shift');
        $this->bid($current, 2026, 'B Shift');
        $this->bid($current, 2027, 'C Shift');
        $this->bid($olderOnly, 2025, 'A Shift');
        $source = app(PersonnelSource::class);
        $record = $source->query()->findOrFail($current->id);
        self::assertSame('B Shift', $source->value($record, 'shift'));
        self::assertSame('2026–2027', $source->value($record, 'bid_term'));
        self::assertSame('Captain', $source->value($record, 'bid_position'));
        self::assertSame('Engine 2', $source->value($record, 'unit'));
        self::assertNull($source->value($source->query()->findOrFail($olderOnly->id), 'shift'));
        self::assertSame([$current->id], $source->filter($source->query(), ['shift' => ['B Shift'], 'rank' => 'Captain'])->pluck('employees.id')->all());
        self::assertSame('B Shift', $source->query()->orderBy('source_shift', 'desc')->first()->source_shift);
        $this->expectException(ValidationException::class);
        $source->filter($source->query(), ['password' => 'must not be a source field']);
    }

    public function test_all_tracking_types_reject_invalid_values_and_preserve_valid_types(): void
    {
        $owner = $this->manager();
        $employee = $this->employee('00711');
        $specs = [
            ['type' => 'text', 'valid' => '=SUM(A1:A2)', 'invalid' => ['nested']],
            ['type' => 'notes', 'valid' => '<script>stored as text</script>', 'invalid' => str_repeat('x', 5001)],
            ['type' => 'number', 'valid' => 2.5, 'invalid' => 'twenty'],
            ['type' => 'date', 'valid' => '2026-10-21', 'invalid' => '2026-99-01'],
            ['type' => 'select', 'valid' => 'Pending', 'invalid' => 'Unknown', 'options' => ['Pending', 'Done']],
            ['type' => 'multiselect', 'valid' => ['Pending', 'Done'], 'invalid' => ['Unknown'], 'options' => ['Pending', 'Done']],
            ['type' => 'status', 'valid' => 'Done', 'invalid' => 'Unknown', 'options' => ['Pending', 'Done']],
        ];
        foreach ($specs as $spec) {
            $table = $this->service->create($owner, ['name' => 'Typed tracking', 'employee_ids' => [$employee->id],
                'columns' => [['label' => 'Tracking', 'type' => $spec['type'], 'options' => $spec['options'] ?? []]]]);
            $id = $table->columns[0]['id'];
            $entry = $this->service->setCell($owner, $table, $employee, $id, $spec['valid'], 0);
            self::assertSame($spec['valid'], $entry->values[$id]);
            try {
                $this->service->setCell($owner, $table, $employee, $id, $spec['invalid'], 1);
                self::fail('Invalid '.$spec['type'].' must be rejected.');
            } catch (ValidationException) {
                self::assertSame($spec['valid'], $entry->fresh()->values[$id]);
            }
        }
    }

    private function manager(): User
    {
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['admin.access', 'admin.personnel.view', 'admin.personnel.manage'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $actor;
    }

    private function employee(string $id): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => 'Fixture member', 'rank' => 'Captain', 'roster_status' => 'active']);
    }

    private function table(User $owner, array $employees = []): PersonnelTable
    {
        return $this->service->create($owner, ['name' => 'Acknowledgment', 'employee_ids' => $employees, 'columns' => [['label' => 'Completed', 'type' => 'checkbox']]]);
    }

    private function bid(Employee $employee, int $year, string $shift): EmployeeBidAssignment
    {
        return EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $employee->id, 'payload_version' => 2, 'bid_year' => $year,
            'term_label' => $year.'–'.($year + 1), 'bid_session_id' => 'fixture-'.$year,
            'position_id' => 'fixture-position', 'rank_label' => 'Captain', 'shift_label' => $shift,
            'station_label' => 'Station 2', 'division_label' => 'Operations', 'unit_label' => 'Engine 2',
            'position_label' => 'Captain', 'bid_selection_label' => 'Engine 2', 'assignment_source' => 'bid_award', 'a_day_label' => 'A-Day 1',
            'idempotency_key' => $employee->id.'-'.$year, 'payload_hash' => str_repeat('a', 64),
            'is_forced' => false,
        ]);
    }
}
