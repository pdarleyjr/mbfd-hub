<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\CustomPersonnelTables;
use App\Filament\Pages\MasterPersonnelDirectory;
use App\Filament\Pages\PersonnelReconciliation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Personnel\PersonnelTableExporter;
use App\Services\Personnel\PersonnelTableService;
use App\Services\Personnel\PersonnelWorkbookReconciler;
use App\Services\Security\ApplicationAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

final class PersonnelUniformPermissionTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    /** @return array<string, array{?string, bool, bool}> */
    public static function viewGrants(): array
    {
        return [
            'Uniform and PPE only' => [null, false, false],
            'Explicit directory view' => ['personnel.directory.view', true, false],
            'Explicit custom tables view' => ['personnel.tables.view', false, true],
        ];
    }

    #[DataProvider('viewGrants')]
    public function test_uniform_permissions_never_supply_personnel_mutation_or_export_rights(?string $viewGrant, bool $directoryView, bool $tablesView): void
    {
        $owner = $this->actingAsSuperAdmin();
        $service = app(PersonnelTableService::class);
        $member = Employee::query()->create(['employee_id' => '00426', 'name' => 'Permission Fixture', 'roster_status' => 'active']);
        $before = $member->fresh()->getRawOriginal();
        $master = $service->master($owner);
        $custom = $service->create($owner, ['name' => 'Shared permission fixture', 'visibility' => 'shared',
            'employee_ids' => [$member->id], 'source_columns' => ['name'], 'columns' => [['label' => 'Completed', 'type' => 'checkbox']]]);
        $actor = User::factory()->create(['account_status' => 'active']);
        foreach ([...ApplicationAccessService::UNIFORM_MANAGER_PERMISSIONS, ...($viewGrant === null ? [] : [$viewGrant])] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($actor);

        self::assertTrue(ApplicationAccessService::isUniformManagerOnly($actor));
        self::assertSame($directoryView, MasterPersonnelDirectory::canAccess());
        self::assertSame($tablesView, CustomPersonnelTables::canAccess());
        self::assertSame($directoryView, PersonnelReconciliation::canAccess());
        self::assertFalse($service->canCreate($actor));
        foreach ([$master, $custom] as $table) {
            self::assertFalse($service->canManage($actor, $table));
            self::assertFalse($service->canExport($actor, $table));
            $this->assertThrows(fn () => $service->update($actor, $table, ['description' => 'Unauthorized change'], $table->version), AuthorizationException::class);
            $this->assertThrows(fn () => app(PersonnelTableExporter::class)->export($actor, $table,
                new Collection([$member]), ['name'], 'csv', 'all'), AuthorizationException::class);
        }
        self::assertFalse(PersonnelWorkbookReconciler::canReconcile($actor));
        self::assertFalse(PersonnelWorkbookReconciler::canExport($actor));
        $this->assertThrows(fn () => app(PersonnelWorkbookReconciler::class)->analyze($actor, 'not-read-for-a-denied-actor.xlsx'),
            fn (HttpException $exception): bool => $exception->getStatusCode() === 403);

        foreach ([
            '/admin/master-personnel-directory' => $directoryView,
            '/admin/custom-personnel-tables' => $tablesView,
            '/admin/custom-personnel-tables/'.$custom->id => $tablesView,
            '/admin/personnel-reconciliation' => $directoryView,
        ] as $url => $allowed) {
            $response = $this->get($url);
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }

        self::assertSame($before, $member->fresh()->getRawOriginal());
        self::assertNull($master->fresh()->description);
        self::assertNull($custom->fresh()->description);
        self::assertSame([], $custom->entries()->sole()->values);
    }

    public function test_uniform_directory_viewer_cannot_change_authoritative_rank_inline(): void
    {
        $member = Employee::query()->create(['employee_id' => '00427', 'name' => 'Read only Fixture',
            'rank' => 'Firefighter', 'roster_status' => 'active']);
        $this->actingAsUniformDirectoryViewer();

        Livewire::test(MasterPersonnelDirectory::class)
            ->call('updateTableColumnState', 'rank', (string) $member->id, 'Captain');

        self::assertSame('Firefighter', $member->fresh()->rank);
        $this->assertDatabaseMissing('employee_profile_events', ['employee_id' => $member->id, 'action' => 'profile_updated', 'result' => 'success']);
    }

    public function test_uniform_directory_viewer_has_no_profile_edit_action(): void
    {
        $member = Employee::query()->create(['employee_id' => '00428', 'name' => 'Profile action Fixture', 'roster_status' => 'active']);
        $this->actingAsUniformDirectoryViewer();

        Livewire::test(MasterPersonnelDirectory::class)->assertTableActionHidden('employee', $member);
    }

    public function test_uniform_directory_viewer_has_no_add_personnel_action(): void
    {
        $this->actingAsUniformDirectoryViewer();

        Livewire::test(MasterPersonnelDirectory::class)->assertActionHidden('addPersonnel');
    }

    private function actingAsUniformDirectoryViewer(): User
    {
        $actor = User::factory()->create(['account_status' => 'active']);
        foreach ([...ApplicationAccessService::UNIFORM_MANAGER_PERMISSIONS, 'personnel.directory.view'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($actor);

        return $actor;
    }
}
