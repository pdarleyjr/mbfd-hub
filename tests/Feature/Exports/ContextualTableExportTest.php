<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Admin\Pages\MedicalSupplyRoom;
use App\Filament\Pages\CustomPersonnelTables;
use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Resources\StationResource;
use App\Filament\Resources\StationResource\RelationManagers\RoomsRelationManager;
use App\Filament\Resources\Workgroup\Pages\ListWorkgroups;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\ListTrainingCourses;
use App\Models\Apparatus;
use App\Models\CapitalProject;
use App\Models\Ems\SupplyItem;
use App\Models\EvaluationTemplate;
use App\Models\InventoryLocation;
use App\Models\PromotionalList;
use App\Models\Station;
use App\Models\Training\TrainingCourse;
use App\Models\Under25kProject;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Services\Exports\ExportableTable;
use App\Services\Exports\TableExportService;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ContextualTableExportTest extends ExportTestCase
{
    public function test_supply_room_current_stock_exports_both_formats_without_making_ledger_editable(): void
    {
        SupplyItem::query()->create(['name' => 'Synthetic tape', 'base_unit' => 'each', 'active' => true, 'controlled_substance' => false]);
        $page = Livewire::test(MedicalSupplyRoom::class)->call('loadTable');
        foreach (['xlsx', 'pdf'] as $format) {
            $page->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered'])->assertHasNoTableActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
        }
        $grid = $this->dataRows($this->workbook($page->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered'])));
        self::assertSame('Synthetic tape', $grid[1][1]);
        self::assertSame('Not counted', $grid[1][2]);
        $page->assertTableActionExists('settings')->assertTableActionDoesNotExist('edit');
    }

    public function test_default_created_date_filter_is_inert_and_uses_eastern_midnight_across_fall_dst(): void
    {
        $courses = [];
        foreach (['2026-11-01 03:59:59', '2026-11-01 04:00:00', '2026-11-02 04:59:59', '2026-11-02 05:00:00'] as $index => $timestamp) {
            $course = TrainingCourse::query()->create(['name' => 'Synthetic boundary '.$index, 'classifications' => ['EMS Training'], 'created_by' => $this->admin->id]);
            $course->forceFill(['created_at' => $timestamp])->save();
            $courses[] = $course;
        }
        $page = Livewire::test(ListTrainingCourses::class)->call('loadTable')->assertCanSeeTableRecords($courses)
            ->assertTableFilterExists('created_between')
            ->filterTable('created_between', ['from' => '2026-11-01', 'until' => '2026-11-01'])
            ->assertCanSeeTableRecords([$courses[1], $courses[2]])->assertCanNotSeeTableRecords([$courses[0], $courses[3]])
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered']);
        self::assertSame(['Synthetic boundary 1', 'Synthetic boundary 2'], array_slice(array_column($this->dataRows($this->workbook($page)), 0), 1));
        $page->removeTableFilters()->assertCanSeeTableRecords($courses);
        foreach ([['from' => '2026-11-02', 'until' => '2026-11-01'], ['from' => '2026-02-30', 'until' => null]] as $invalid) {
            $page->filterTable('created_between', $invalid)->assertCanNotSeeTableRecords($courses);
            self::assertStringContainsString('invalid', implode(' ', array_map(static fn ($indicator): string => (string) $indicator->getLabel(), $page->instance()->getTable()->getFilterIndicators())));
        }
    }

    public function test_relation_export_is_limited_to_its_parent_and_refuses_revoked_parent_access(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $station->rooms()->create(['name' => 'North room', 'room_type' => 'storage', 'floor' => 1]);
        $other->rooms()->create(['name' => 'Other station room', 'room_type' => 'storage', 'floor' => 1]);
        $pageClass = StationResource::getPages()['view']->getPage();
        $page = Livewire::test(RoomsRelationManager::class, ['ownerRecord' => $station, 'pageClass' => $pageClass])->call('loadTable');
        $page->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'all']);
        self::assertSame(['North room'], array_slice(array_column($this->dataRows($this->workbook($page)), 0), 1));

        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse(app(TableExportService::class)->canExport($limited, RoomsRelationManager::class, $page->instance()));
        try {
            app(TableExportService::class)->download($limited, $page->instance(), RoomsRelationManager::class, 'pdf', 'all');
            self::fail('Revoked parent access must prevent a relation export.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_registered_denying_policy_cannot_be_bypassed_by_custom_resource_access(): void
    {
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        Gate::policy(InventoryLocation::class, DenyingExportPolicy::class);
        self::assertTrue(CustomAccessExportResource::canViewAny());
        self::assertFalse(app(TableExportService::class)->canExport($limited, CustomAccessExportResource::class));
    }

    public function test_published_shield_resource_preserves_native_role_policy_and_route_contract(): void
    {
        $resources = Filament::getPanel('admin')->getResources();
        self::assertContains(\App\Filament\Resources\RoleResource::class, $resources);
        self::assertNotContains(\BezhanSalleh\FilamentShield\Resources\RoleResource::class, $resources);
        self::assertSame(config('filament-shield.shield_resource.slug'), \App\Filament\Resources\RoleResource::getSlug());
        foreach (['index' => 'ListRoles', 'create' => 'CreateRole', 'edit' => 'EditRole'] as $route => $page) {
            self::assertTrue(is_subclass_of(\App\Filament\Resources\RoleResource::getPages()[$route]->getPage(), 'BezhanSalleh\\FilamentShield\\Resources\\RoleResource\\Pages\\'.$page));
        }
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse(\App\Filament\Resources\RoleResource::canViewAny());
        self::assertFalse(\App\Filament\Resources\RoleResource::canExportTable());
    }

    public function test_native_ems_access_without_a_model_policy_is_rechecked_for_the_same_actor(): void
    {
        $viewer = $this->userWith('admin.access', 'admin.ems.view');
        $this->actingAs($viewer);
        self::assertNull(Gate::getPolicyFor(InventoryRequestResource::getModel()));
        self::assertTrue(InventoryRequestResource::canViewAny());
        self::assertTrue(app(TableExportService::class)->canExport($viewer, InventoryRequestResource::class));
        self::assertFalse(app(TableExportService::class)->canExport($this->admin, InventoryRequestResource::class));
    }

    public function test_saved_personnel_table_definitions_export_with_their_native_access_scope(): void
    {
        foreach (['xlsx', 'pdf'] as $format) {
            $page = Livewire::test(CustomPersonnelTables::class)->call('loadTable')
                ->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered'])->assertHasNoTableActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
        }
    }

    public function test_scoped_workgroup_resource_keeps_its_registered_policy_export_gate(): void
    {
        $allowed = $this->owner(Workgroup::class);
        $hidden = $this->owner(Workgroup::class);
        $allowed->update(['name' => 'Authorized export workgroup']);
        $hidden->update(['name' => 'Hidden workgroup']);
        $member = $this->userWith('admin.access');
        WorkgroupMember::query()->create(['workgroup_id' => $allowed->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true]);
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(ListWorkgroups::class)->call('loadTable')
            ->assertCanSeeTableRecords([$allowed])->assertCanNotSeeTableRecords([$hidden])->assertTableActionHidden('export');
        self::assertFalse(app(TableExportService::class)->canExport($member, \App\Filament\Resources\Workgroup\WorkgroupResource::class));
    }

    public function test_every_registered_relation_manager_exports_pdf_and_excel(): void
    {
        Livewire::withoutLazyLoading();
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            foreach ($resource::getRelations() as $relation) {
                self::assertTrue(is_string($relation) && is_subclass_of($relation, ExportableTable::class), $resource.' relation requires secure export support.');
                $owner = $this->owner($resource::getModel());
                $pages = $resource::getPages();
                $pageClass = ($pages['view'] ?? $pages['edit'])->getPage();
                foreach (['xlsx', 'pdf'] as $format) {
                    RateLimiter::clear('table-export:'.$this->admin->getKey());
                    $page = Livewire::test($relation, ['ownerRecord' => $owner, 'pageClass' => $pageClass]);
                    self::assertNotNull($page->instance(), $relation.' must mount for an authorized owner.');
                    $page->assertSuccessful()->call('loadTable');
                    self::assertNotNull($page->instance(), $relation.' must retain its authorized owner when loading rows.');
                    if ($page->instance()->getTable()->getFilter('created_between') !== null) {
                        $model = $page->instance()->getTable()->getQuery()->getModel();
                        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), $model->getCreatedAtColumn()), $relation.' must have a real created-at filter column.');
                    }
                    $page->assertTableActionVisible('export')->assertTableBulkActionExists('export_selected')
                        ->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered'])->assertHasNoTableActionErrors();
                    self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page), $relation.' '.$format);
                }
            }
        }
    }

    private function station(int $number): Station
    {
        return Station::query()->create(['station_number' => $number, 'name' => 'Synthetic Station '.$number, 'address' => 'Synthetic fixture', 'is_active' => true]);
    }

    private function owner(string $model): Model
    {
        return match ($model) {
            Station::class => $this->station(Station::query()->count() + 1),
            Apparatus::class => $this->apparatus(),
            CapitalProject::class => CapitalProject::factory()->create(),
            Under25kProject::class => Under25kProject::query()->create(['project_number' => 'FIRE-EXPORT-'.Under25kProject::query()->count(), 'name' => 'Synthetic export project', 'status' => 'Pending']),
            UnitMasterVehicle::class => UnitMasterVehicle::query()->create(['veh_number' => 'EXPORT-'.UnitMasterVehicle::query()->count()]),
            PromotionalList::class => PromotionalList::query()->create(['list_type' => 'lieutenant', 'title' => 'Synthetic export list', 'effective_date' => '2026-04-01', 'status' => 'active', 'source_reference' => 'Synthetic fixture']),
            Workgroup::class => Workgroup::query()->create(['name' => 'Synthetic export workgroup', 'is_active' => true, 'created_by' => $this->admin->id]),
            WorkgroupSession::class => WorkgroupSession::query()->create(['workgroup_id' => $this->owner(Workgroup::class)->getKey(), 'name' => 'Synthetic export session', 'status' => 'active', 'start_date' => '2026-10-26', 'end_date' => '2026-10-27']),
            EvaluationTemplate::class => EvaluationTemplate::query()->create(['name' => 'Synthetic export template', 'is_active' => true,
                'category_id' => \App\Models\EvaluationCategory::query()->create(['name' => 'Synthetic export category', 'is_active' => true])->getKey()]),
            default => throw new \LogicException('Add an explicit fixture for '.$model),
        };
    }

    private function apparatus(): Apparatus
    {
        $number = Apparatus::query()->count();
        $apparatus = Apparatus::query()->create(['unit_id' => 'EXP-'.$number, 'designation' => 'EXP-'.$number,
            'name' => 'Synthetic export unit', 'vehicle_number' => 'EXPORT-'.$number, 'slug' => 'export-unit-'.$number, 'type' => 'Engine', 'status' => 'In Service']);
        $apparatus->identificationQuestions()->create(['field' => 'plate', 'state' => 'open']);

        return $apparatus;
    }
}

final class CustomAccessExportResource extends Resource
{
    protected static ?string $model = InventoryLocation::class;

    public static function canViewAny(): bool
    {
        return true;
    }
}

final class DenyingExportPolicy
{
    public function viewAny(User $actor): bool
    {
        return false;
    }
}
