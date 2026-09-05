<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\Pages\ListApparatuses;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\DefectsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\InspectionsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\ServiceTicketsRelationManager;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ApparatusProfileAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private User $panelUser;

    private int $apparatusSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('logistics_admin', 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('view_any_apparatus', 'web'),
            Permission::findOrCreate('view_apparatus', 'web'),
            Permission::findOrCreate('update_apparatus', 'web'),
            Permission::findOrCreate('view_any_inspection', 'web'),
            Permission::findOrCreate('view_inspection', 'web'),
            Permission::findOrCreate('view_any_defect', 'web'),
            Permission::findOrCreate('view_defect', 'web'),
        ]);

        $this->panelUser = $this->actingAsCanonicalFixture('E01-PROFILE', 'Apparatus Profile Actor');
        $this->panelUser->assignRole($role);
        $this->panelUser->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.fleet.view', 'web'),
            Permission::findOrCreate('admin.fleet.manage', 'web'),
        ]);
        $this->bindCanonicalSessionToLivewireTestRequests();

        $this->station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main Street',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    public function test_profile_route_and_each_relation_manager_render_for_every_daily_checkout_state(): void
    {
        $zeroInspections = $this->createApparatus('Zero inspections');

        $approvedInspection = $this->createApparatus('Approved inspection');
        $this->createInspection($approvedInspection, 'approved');

        $pendingReview = $this->createApparatus('Pending review submission');
        $this->submitDailyCheckout($pendingReview);
        $this->assertSame('pending_review', ApparatusInspection::query()->where('apparatus_id', $pendingReview->id)->sole()->review_status);

        $activeDefect = $this->createApparatus('Active defect');
        $this->createDefect($activeDefect, 'open', 'missing');

        $resolvedDefect = $this->createApparatus('Resolved defect');
        $this->createDefect($resolvedDefect, 'resolved', 'damaged');

        $legacyDefect = $this->createApparatus('Legacy defect');
        DB::table('apparatus_defects')->insert([
            [
                'apparatus_id' => $legacyDefect->id,
                'compartment' => 'Cab',
                'item' => 'Legacy radio',
                'status' => 'MiSsInG',
                'issue_type' => 'other',
                'reported_date' => now()->toDateString(),
                'notes' => 'Legacy Missing state from a submitted checkout.',
                'resolved' => false,
                'resolved_at' => null,
                'resolution_notes' => null,
                'defect_history' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'apparatus_id' => $legacyDefect->id,
                'compartment' => 'Rear',
                'item' => 'Legacy scene light',
                'status' => 'dAmAgEd',
                'issue_type' => 'other',
                'reported_date' => now()->toDateString(),
                'notes' => 'Legacy Damaged state from a submitted checkout.',
                'resolved' => false,
                'resolved_at' => null,
                'resolution_notes' => null,
                'defect_history' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path('migrations/2026_08_28_000001_normalize_legacy_apparatus_defect_workflow_states.php');
        $migration->up();

        $normalizedMissing = ApparatusDefect::query()->where('item', 'Legacy radio')->sole();
        $normalizedDamaged = ApparatusDefect::query()->where('item', 'Legacy scene light')->sole();
        $this->assertSame('open', $normalizedMissing->status);
        $this->assertSame('missing', $normalizedMissing->issue_type);
        $this->assertFalse($normalizedMissing->resolved);
        $this->assertSame('open', $normalizedDamaged->status);
        $this->assertSame('damaged', $normalizedDamaged->issue_type);
        $this->assertFalse($normalizedDamaged->resolved);

        foreach ([
            'zero inspections' => $zeroInspections,
            'approved Daily inspection' => $approvedInspection,
            'pending_review Daily inspection submitted through the public checkout route' => $pendingReview,
            'active defect' => $activeDefect,
            'resolved defect' => $resolvedDefect,
            'legacy Missing/Damaged data normalized by the migration' => $legacyDefect,
        ] as $state => $apparatus) {
            $this->assertProfileAndRelationManagersRender($apparatus, $state);
        }
    }

    public function test_table_navigation_targets_the_canonical_profile_after_a_submitted_daily_checkout(): void
    {
        $apparatus = $this->createApparatus('Table navigation');
        $this->submitDailyCheckout($apparatus);
        $profileUrl = ApparatusResource::getUrl('edit', ['record' => $apparatus]);

        $list = Livewire::test(ListApparatuses::class)
            ->call('loadTable');
        $list->assertSuccessful();
        $list->assertCanSeeTableRecords([$apparatus]);
        $list->assertSeeHtml('href="'.$profileUrl.'"');

        $this->assertSame($profileUrl, $list->instance()->getTable()->getRecordUrl($apparatus));
        $this->get($profileUrl)->assertOk();
    }

    public function test_view_only_apparatus_user_can_navigate_to_a_profile_after_a_submitted_daily_checkout(): void
    {
        $apparatus = $this->createApparatus('View-only navigation');
        $this->submitDailyCheckout($apparatus);

        $this->actingAsCanonicalUser($this->viewOnlyApparatusUser());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $list = Livewire::test(ListApparatuses::class)
            ->call('loadTable');
        $list->assertSuccessful();
        $list->assertCanSeeTableRecords([$apparatus]);

        $profileUrl = $list->instance()->getTable()->getRecordUrl($apparatus);

        $this->assertNotNull($profileUrl, 'A user who can view Fire Apparatus must have a profile navigation target.');
        $this->assertSame(ApparatusResource::getUrl('view', ['record' => $apparatus]), $profileUrl);
        $this->get($profileUrl)->assertOk();
        $this->get(ApparatusResource::getUrl('edit', ['record' => $apparatus]))->assertForbidden();
    }

    private function assertProfileAndRelationManagersRender(Apparatus $apparatus, string $state): void
    {
        $profileUrl = ApparatusResource::getUrl('view', ['record' => $apparatus]);

        $this->get($profileUrl)
            ->assertOk()
            ->assertSee('Vehicle Inspections')
            ->assertSee('Missing / Damaged Equipment')
            ->assertSee('Service Tickets');
        $this->get(ApparatusResource::getUrl('edit', ['record' => $apparatus]))->assertOk();

        $component = Livewire::test(ViewApparatus::class, ['record' => $apparatus->getRouteKey()]);
        $component->assertSuccessful();

        foreach ([
            '0' => 'Vehicle Inspections',
            '1' => 'Missing / Damaged Equipment',
            '2' => 'Service / Maintenance History',
        ] as $relationManager => $title) {
            $component->set('activeRelationManager', $relationManager);
            $component->assertSuccessful();
            $component->assertSee($title, "{$state} profile did not render the {$title} relation manager.");
        }

        $inspections = Livewire::test(InspectionsRelationManager::class, [
            'ownerRecord' => $apparatus,
            'pageClass' => ViewApparatus::class,
            'lazy' => false,
        ])
            ->call('loadTable');
        $inspections->assertSuccessful();

        if ($inspection = $apparatus->inspections()->first()) {
            $inspections->assertCanSeeTableRecords([$inspection]);
        }

        $defects = Livewire::test(DefectsRelationManager::class, [
            'ownerRecord' => $apparatus,
            'pageClass' => ViewApparatus::class,
            'lazy' => false,
        ])
            ->call('loadTable');
        $defects->assertSuccessful();

        $defectRecords = $apparatus->defects()->get();
        if ($defectRecords->isNotEmpty()) {
            $defects->assertCanSeeTableRecords($defectRecords->all());
        }

        Livewire::test(ServiceTicketsRelationManager::class, [
            'ownerRecord' => $apparatus,
            'pageClass' => ViewApparatus::class,
            'lazy' => false,
        ])
            ->call('loadTable')
            ->assertSuccessful();
    }

    private function createApparatus(string $label): Apparatus
    {
        $sequence = ++$this->apparatusSequence;

        return Apparatus::query()->create([
            'station_id' => $this->station->id,
            'unit_id' => "PROFILE-{$sequence}",
            'name' => "Profile {$label}",
            'type' => 'Engine',
            'vehicle_number' => "PROFILE-{$sequence}",
            'designation' => "P{$sequence}",
            'slug' => "profile-{$sequence}",
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
            'current_engine_hours' => 100.0,
            'current_miles' => 10_000,
        ]);
    }

    private function createInspection(Apparatus $apparatus, string $reviewStatus): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Daily Checkout Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $apparatus->vehicle_number,
            'vehicle_number' => $apparatus->vehicle_number,
            'designation_at_time' => $apparatus->designation,
            'review_status' => $reviewStatus,
            'completed_at' => now(),
        ]);
    }

    private function createDefect(Apparatus $apparatus, string $status, string $issueType): ApparatusDefect
    {
        return ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Cab',
            'item' => "{$issueType} radio",
            'status' => $status,
            'issue_type' => $issueType,
            'reported_date' => now()->toDateString(),
            'notes' => 'Profile regression fixture.',
            'resolved_at' => $status === 'resolved' ? now() : null,
        ]);
    }

    private function submitDailyCheckout(Apparatus $apparatus): void
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $this->assertTrue($resolution['usable']);
        $this->assertIsArray($resolution['checklist']);
        $this->assertIsString($resolution['checklist_version']);

        $compartments = array_map(static function (array $compartment): array {
            $compartmentId = (string) $compartment['id'];

            return [
                'id' => $compartmentId,
                'name' => (string) ($compartment['name'] ?? $compartment['title']),
                'items' => array_map(static fn (array $item, int $index): array => [
                    'id' => (string) ($item['id'] ?? "{$compartmentId}-item-".($index + 1)),
                    'name' => (string) $item['name'],
                    'status' => 'Present',
                    'notes' => null,
                ], $compartment['items'], array_keys($compartment['items'])),
            ];
        }, $resolution['checklist']['compartments']);

        $suffix = str_pad((string) $apparatus->id, 12, '0', STR_PAD_LEFT);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", [
            'client_submission_id' => "00000000-0000-4000-8000-{$suffix}",
            'checklist_version' => $resolution['checklist_version'],
            'operator_name' => 'Daily Checkout Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => 'UNTRUSTED-CLIENT-UNIT',
            'compartments' => $compartments,
            'defects' => [],
        ])->assertCreated();

        $this->actingAsCanonicalUser($this->panelUser);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function viewOnlyApparatusUser(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('admin', 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('view_any_apparatus', 'web'),
            Permission::findOrCreate('view_apparatus', 'web'),
            Permission::findOrCreate('view_any_inspection', 'web'),
            Permission::findOrCreate('view_inspection', 'web'),
            Permission::findOrCreate('view_any_defect', 'web'),
            Permission::findOrCreate('view_defect', 'web'),
        ]);

        $user = $this->actingAsCanonicalFixture('E01-PROFILE-VIEW', 'View-only Apparatus Actor');
        $user->assignRole($role);
        $user->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.fleet.view', 'web'),
        ]);

        return $user;
    }
}
