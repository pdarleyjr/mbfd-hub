<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ApparatusInspectionApprovalActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_processing_display_does_not_imply_a_human_approval(): void
    {
        $inspection = $this->pendingInspection();
        $inspection->update(['processing_status' => 'accepted', 'review_status' => 'approved', 'pending_effects' => null]);
        $this->actingAs($this->userWithRole('logistics_admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        $this->assertSame('Accepted', $inspection->displayStatus());
        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Accepted')
            ->assertDontSee('Approved')
            ->assertDontSee('Pending Review Evidence');

        $inspection->update(['processing_status' => 'accepted_with_exception']);
        $this->assertSame('Follow-up needed', $inspection->displayStatus());
        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Follow-up needed')
            ->assertDontSee('Approved');

        $this->assertSame('Pending review', (new ApparatusInspection(['review_status' => 'pending_review']))->displayStatus());
        $this->assertSame('Approved', (new ApparatusInspection(['review_status' => 'approved']))->displayStatus());
    }

    public function test_admin_sees_download_pdf_action_linking_to_the_inspection_pdf(): void
    {
        $inspection = $this->pendingInspection();
        $this->actingAs($this->userWithRole('logistics_admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('downloadPdf')
            ->assertActionHasUrl('downloadPdf', route('employee.vehicle-inspections.pdf', $inspection))
            ->assertActionShouldNotOpenUrlInNewTab('downloadPdf')
            ->assertActionVisible('print');
    }

    public function test_authorized_reviewer_can_see_pending_evidence_and_approve_from_the_apparatus_inspection_view(): void
    {
        $inspection = $this->pendingInspection();
        $reviewer = $this->userWithRole('logistics_admin');

        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Reported pending defects')
            ->assertSee('Portable radio')
            ->assertActionVisible('approveInspection')
            ->callAction('approveInspection', data: ['mark_out_of_service' => true])
            ->assertHasNoActionErrors();

        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertNull($inspection->fresh()->pending_effects);
        $this->assertSame('Out of Service', $inspection->apparatus->fresh()->status);
        $this->assertDatabaseHas('apparatus_operational_status_events', [
            'apparatus_id' => $inspection->apparatus_id,
            'previous_status' => 'In Service',
            'status' => 'Out of Service',
        ]);
        $this->assertSame($inspection->id, ApparatusDefect::sole()->apparatus_inspection_id);
        $this->assertDatabaseHas('apparatus_inspection_review_events', [
            'apparatus_inspection_id' => $inspection->id,
            'previous_status' => 'pending_review',
            'status' => 'approved',
            'changed_by_user_id' => $reviewer->id,
        ]);
    }

    public function test_reviewer_can_approve_critical_defects_without_taking_the_unit_out_of_service(): void
    {
        $inspection = $this->pendingInspection();
        $reviewer = $this->userWithRole('logistics_admin');

        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->callAction('approveInspection', data: ['mark_out_of_service' => false])
            ->assertHasNoActionErrors();

        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame('In Service', $inspection->apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_operational_status_events', 0);
        $this->assertSame($inspection->id, ApparatusDefect::sole()->apparatus_inspection_id);
        $this->assertSame('10010', (string) $inspection->apparatus->fresh()->current_miles);
    }

    public function test_approve_form_never_pre_selects_out_of_service_even_when_critical_defects_were_reported(): void
    {
        $withDefects = $this->pendingInspection();
        $reviewer = $this->userWithRole('logistics_admin');

        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $withDefects->getRouteKey()])
            ->mountAction('approveInspection')
            ->assertActionDataSet(['mark_out_of_service' => false]);
    }

    public function test_authorized_reviewer_can_reject_pending_evidence_without_applying_operational_effects(): void
    {
        $inspection = $this->pendingInspection();
        $reviewer = $this->userWithRole('logistics_admin');

        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('rejectInspection')
            ->callAction('rejectInspection', data: [
                'reason' => 'Duplicate submission; daily officer will resubmit the verified inspection.',
            ])
            ->assertHasNoActionErrors();

        $rejected = $inspection->fresh();
        $this->assertSame('rejected', $rejected->review_status);
        $this->assertNotNull($rejected->pending_effects);
        $this->assertSame('In Service', $inspection->apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertDatabaseHas('apparatus_inspection_review_events', [
            'apparatus_inspection_id' => $inspection->id,
            'previous_status' => 'pending_review',
            'status' => 'rejected',
            'internal_note' => 'Duplicate submission; daily officer will resubmit the verified inspection.',
            'changed_by_user_id' => $reviewer->id,
        ]);
    }

    public function test_authorized_reviewer_can_see_v2_checklist_evidence_before_and_after_review_in_both_inspection_views(): void
    {
        $inspection = $this->pendingInspectionWithV2Evidence();
        $reviewer = $this->userWithRole('logistics_admin');

        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Checklist v2 evidence')
            ->assertSee('High Low Tide')
            ->assertSee('High 10:00 / Low 16:30')
            ->assertSee('Fuel Tank Hold')
            ->assertSee('Every Monday');

        app(ApparatusInspectionApprovalService::class)->approve($inspection->id, $reviewer);

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Review history')
            ->assertSee('Checklist v2 evidence')
            ->assertSee('High Low Tide')
            ->assertSee('High 10:00 / Low 16:30')
            ->assertSee('Fuel Tank Hold')
            ->assertSee('Every Monday');
    }

    public function test_non_reviewer_cannot_approve_a_pending_inspection_from_the_apparatus_view(): void
    {
        $inspection = $this->pendingInspection();
        $user = $this->userWithRole('training_admin');

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('approveInspection');

        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame('In Service', $inspection->apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_defects', 0);
    }

    public function test_review_history_is_append_only_and_prevents_parent_inspection_deletion(): void
    {
        $inspection = $this->pendingInspection();
        $reviewer = $this->userWithRole('admin');

        app(ApparatusInspectionApprovalService::class)->reject(
            $inspection->id,
            $reviewer,
            'Duplicate evidence.',
        );
        $event = ApparatusInspectionReviewEvent::sole();

        try {
            $event->update(['internal_note' => 'Altered note']);
            $this->fail('A review event update should be blocked.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $inspection->fresh()->delete();
            $this->fail('An inspection with review history should not be deleted.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_notification_target_is_the_canonical_page_with_the_full_evidence_and_review_actions(): void
    {
        $inspection = $this->pendingInspection();
        $reviewer = $this->userWithRole('admin');

        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Pending officer review')
            ->assertSee('Reported pending defects')
            ->assertSee('Portable radio')
            ->assertActionVisible('approveInspection')
            ->assertActionVisible('rejectInspection');

        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame('In Service', $inspection->apparatus->fresh()->status);
    }

    public function test_user_with_only_list_permission_cannot_open_an_apparatus_inspection_by_url(): void
    {
        $inspection = $this->pendingInspection();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('logistics_admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('view_any_apparatus', 'web'));
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        $this->get(route('filament.admin.resources.apparatuses.view-inspection', [
            'record' => $inspection->apparatus_id,
            'inspection' => $inspection->id,
        ]))->assertForbidden();
    }

    private function pendingInspection(): ApparatusInspection
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main Street',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => '1001',
            'designation' => 'E1',
            'slug' => 'engine-1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'current_engine_hours' => 100.0,
            'current_miles' => 10_000,
        ]);

        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Daily Checkout Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => 'E1',
            'engine_hours' => 101.5,
            'miles' => 10_010,
            'review_status' => 'pending_review',
            'pending_effects' => [
                'defects' => [[
                    'compartment' => 'Cab',
                    'item' => 'Portable radio',
                    'status' => 'Missing',
                    'notes' => 'Not in assigned mount.',
                ]],
                'has_critical_defects' => true,
            ],
            'completed_at' => now(),
        ]);
    }

    private function pendingInspectionWithV2Evidence(): ApparatusInspection
    {
        $inspection = $this->pendingInspection();
        $pendingEffects = $inspection->pending_effects;
        $pendingEffects['checklist_v2'] = [
            'template_id' => 'fire_boat_6_daily',
            'template_version' => '2026-07',
            'field_values' => [[
                'id' => 'fb6-high-low-tide',
                'name' => 'High Low Tide',
                'input_type' => 'text',
                'required' => false,
                'value' => 'High 10:00 / Low 16:30',
            ]],
            'scheduled_tasks' => [[
                'id' => 'fb6-monday-fuel-tank-hold',
                'name' => 'Fuel Tank Hold',
                'instructions' => 'Fuel Tank Hold / Check Fuel Filters',
                'recurrence' => [
                    'type' => 'weekday',
                    'weekday' => 'monday',
                ],
                'recurrence_label' => 'Every Monday',
                'status' => 'Present',
                'notes' => null,
            ]],
        ];
        $inspection->update(['pending_effects' => $pendingEffects]);

        return $inspection->refresh();
    }

    private function userWithRole(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate($roleName, 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('view_any_apparatus', 'web'),
            Permission::findOrCreate('view_apparatus', 'web'),
            Permission::findOrCreate('view_any_inspection', 'web'),
            Permission::findOrCreate('view_inspection', 'web'),
            Permission::findOrCreate('update_apparatus', 'web'),
        ]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
