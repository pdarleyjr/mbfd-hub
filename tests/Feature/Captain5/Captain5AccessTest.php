<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\User;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Captain5\Captain5AssignmentService;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Captain5Access;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

final class Captain5AccessTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCaptain5World();
    }

    public function test_every_automatically_eligible_group_can_open_the_workspace_and_others_cannot(): void
    {
        $access = app(Captain5Access::class);
        foreach (['Lieutenant', 'Captain', 'Division Chief', 'Deputy Fire Chief', 'Fire Chief', '  captain '] as $rank) {
            self::assertTrue($access->decide($this->officer($rank))->canViewPage, "{$rank} must open the workspace");
        }
        foreach (['Firefighter', 'Driver Engineer', 'EMS Coordinator', 'Chief Plans Analyst #3', 'Capt', 'Lt', 'Captain 5', ''] as $rank) {
            $decision = $access->decide($this->officer($rank));
            self::assertFalse($decision->canViewPage, "{$rank} must not open the workspace");
            self::assertFalse($decision->canAct);
        }

        $panel = $this->officer('Firefighter');
        $panel->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->forgetCaptain5Memo();
        self::assertTrue($access->decide($panel->fresh())->canViewPage, 'Admin panel users see the workspace');
        self::assertFalse($access->decide($panel->fresh())->canAct, 'Admin panel alone never acts on issues');
        self::assertTrue($access->decide($this->superAdmin())->canViewPage);
    }

    public function test_officer_ranks_view_but_only_position_holders_act(): void
    {
        $access = app(Captain5Access::class);
        $lieutenant = $access->decide($this->officer('Lieutenant'));
        self::assertTrue($lieutenant->canViewData);
        self::assertFalse($lieutenant->canAct);
        self::assertFalse($lieutenant->hasOnDutyAuthority);
        self::assertFalse($lieutenant->canDismiss);

        $bidHolder = $this->officer('Captain');
        $this->seat($bidHolder, 'Captain 5', 'A Shift');
        $decision = app(Captain5Access::class)->decide($bidHolder);
        self::assertTrue($decision->canAct);
        self::assertTrue($decision->hasOnDutyAuthority, 'The A-shift Captain 5 holder is on duty on an A day');
        self::assertTrue($decision->canDismiss);
        self::assertSame('on_duty_bid_holder', $decision->authority());

        $offShift = $this->officer('Captain');
        $this->seat($offShift, 'Captain 5', 'B Shift');
        $offDecision = app(Captain5Access::class)->decide($offShift);
        self::assertTrue($offDecision->canAct, 'Seat holders act even off shift');
        self::assertFalse($offDecision->hasOnDutyAuthority, 'Only the on-duty seat holder has on-duty authority');
        self::assertFalse($offDecision->canDismiss);

        $ems = $this->officer('Captain');
        $this->seat($ems, 'EMS', 'D Shift');
        $emsDecision = app(Captain5Access::class)->decide($ems);
        self::assertTrue($emsDecision->canAct);
        self::assertTrue($emsDecision->canDismiss);
        self::assertTrue($emsDecision->canManageAssignments);
        self::assertFalse($emsDecision->hasOnDutyAuthority);
    }

    public function test_the_on_duty_bid_holder_follows_the_rotation(): void
    {
        $a = $this->officer('Captain', 'Captain A');
        $b = $this->officer('Captain', 'Captain B');
        $this->seat($a, 'Captain 5', 'A Shift');
        $this->seat($b, 'Captain 5', 'B Shift');

        self::assertSame('A', app(Captain5Access::class)->decide($a)->onDuty->shiftLetter);
        self::assertTrue(app(Captain5Access::class)->decide($a)->hasOnDutyAuthority);

        // 07:59 the next morning is still the A duty day; 08:00 is B.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 07:59:00', 'America/New_York'));
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($a)->hasOnDutyAuthority);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', 'America/New_York'));
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($a)->hasOnDutyAuthority);
        self::assertTrue(app(Captain5Access::class)->decide($b)->hasOnDutyAuthority);
    }

    public function test_acting_assignment_grants_on_duty_authority_for_its_duty_day_only(): void
    {
        $holder = $this->officer('Captain');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $acting = $this->officer('Lieutenant', 'Acting Officer');
        $manager = $this->captain5Administrator();

        app(Captain5AssignmentService::class)->assign($manager, [
            'employee_profile_id' => $acting->employee_profile_id,
            'duty_date' => '2026-10-20',
            'reason' => 'Out of class: Captain 5 on leave',
        ]);
        $this->forgetCaptain5Memo();

        $decision = app(Captain5Access::class)->decide($acting);
        self::assertTrue($decision->hasOnDutyAuthority);
        self::assertTrue($decision->canAct);
        self::assertTrue($decision->canDismiss);
        self::assertSame('on_duty_acting', $decision->authority());
        self::assertSame(OnDutyCaptain5::SOURCE_ACTING, $decision->onDuty->source);
        self::assertFalse(app(Captain5Access::class)->decide($holder)->hasOnDutyAuthority, 'Acting coverage supersedes the bid holder for that day');

        // Expired: the next duty day the acting officer is just a Lieutenant again.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:01', 'America/New_York'));
        $this->forgetCaptain5Memo();
        $after = app(Captain5Access::class)->decide($acting->fresh());
        self::assertFalse($after->hasOnDutyAuthority);
        self::assertFalse($after->canAct);
        self::assertTrue($after->canViewData, 'Rank still opens the workspace read-only');
    }

    public function test_a_firefighter_acting_out_of_class_gets_access_only_while_assigned(): void
    {
        $firefighter = $this->officer('Firefighter');
        self::assertFalse(app(Captain5Access::class)->decide($firefighter)->canViewPage);
        $this->actingAssignment($firefighter, $this->captain5Administrator());
        self::assertTrue(app(Captain5Access::class)->decide($firefighter)->canViewPage);

        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00:00', 'America/New_York'));
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($firefighter)->canViewPage, 'Stale assignment grants nothing');
    }

    public function test_revoked_assignment_removes_authority_immediately_and_double_booking_is_refused(): void
    {
        $manager = $this->captain5Administrator();
        $first = $this->officer('Lieutenant');
        $second = $this->officer('Lieutenant');
        $service = app(Captain5AssignmentService::class);
        $assignment = $service->assign($manager, ['employee_profile_id' => $first->employee_profile_id, 'duty_date' => '2026-10-20', 'reason' => 'Coverage']);

        try {
            $service->assign($manager, ['employee_profile_id' => $second->employee_profile_id, 'duty_date' => '2026-10-20', 'reason' => 'Coverage']);
            self::fail('A second acting Captain 5 for the same duty day must be refused');
        } catch (ValidationException) {
        }

        $service->revoke($manager, $assignment->id, 'Captain 5 returned');
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($first)->hasOnDutyAuthority);
        self::assertNotNull($assignment->fresh()->revoked_at);
        self::assertDatabaseHas('security_action_events', ['action' => 'captain5_acting_revoked', 'actor_user_id' => $manager->id]);
    }

    public function test_only_managers_can_assign_and_rank_never_infers_assignment_rights(): void
    {
        $captain = $this->officer('Captain');
        $this->expectException(AuthorizationException::class);
        app(Captain5AssignmentService::class)->assign($captain, [
            'employee_profile_id' => $captain->employee_profile_id, 'duty_date' => '2026-10-20', 'reason' => 'Self-assign attempt',
        ]);
    }

    public function test_deny_override_beats_every_automatic_source_except_super_admin(): void
    {
        $admin = $this->captain5Administrator();
        $holder = $this->officer('Captain');
        $this->seat($holder, 'Captain 5', 'A Shift');
        app(Captain5AdministrationService::class)->setOverride($admin, (int) $holder->employee_profile_id, 'deny', 'Under review');
        $this->forgetCaptain5Memo();

        $decision = app(Captain5Access::class)->decide($holder);
        self::assertTrue($decision->denied);
        self::assertFalse($decision->canViewPage);
        self::assertFalse($decision->canAct);
        self::assertFalse($decision->hasOnDutyAuthority);

        $protected = $this->superAdmin();
        $employeeLinkedSuper = $this->officer('Captain');
        $employeeLinkedSuper->assignRole(Role::findOrCreate('super_admin', 'web'));
        app(Captain5AdministrationService::class)->setOverride($protected, (int) $employeeLinkedSuper->employee_profile_id, 'deny', 'Test');
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($employeeLinkedSuper->fresh())->canViewPage, 'super_admin is protected recovery access');

        app(Captain5AdministrationService::class)->setOverride($admin, (int) $holder->employee_profile_id, 'automatic', 'Review complete');
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($holder)->canAct);
        $history = Captain5AccessOverride::query()->where('employee_profile_id', $holder->employee_profile_id)->get();
        self::assertCount(1, $history, 'The deny decision stays in history');
        self::assertNotNull($history->first()->superseded_at, 'Returning to automatic supersedes it');
        self::assertSame($admin->id, (int) $history->first()->superseded_by_user_id);
        self::assertDatabaseHas('security_action_events', ['action' => 'change_captain5_access', 'target_user_id' => $holder->id]);
    }

    public function test_allow_overrides_grant_view_or_act_and_never_admin_panel_access(): void
    {
        $admin = $this->captain5Administrator();
        $viewer = $this->officer('Firefighter');
        $actor = $this->officer('Firefighter');
        app(Captain5AdministrationService::class)->setOverride($admin, (int) $viewer->employee_profile_id, 'allow_view', 'Training');
        app(Captain5AdministrationService::class)->setOverride($admin, (int) $actor->employee_profile_id, 'allow_act', 'EMS logistics delegate');
        $this->forgetCaptain5Memo();

        $view = app(Captain5Access::class)->decide($viewer);
        self::assertTrue($view->canViewData);
        self::assertFalse($view->canAct);
        $act = app(Captain5Access::class)->decide($actor);
        self::assertTrue($act->canAct);
        self::assertFalse($act->canDismiss);
        self::assertFalse($act->canConfigure);
        self::assertFalse($actor->fresh()->hasCurrentAdminPanelEntitlement(), 'No Admin Panel access is ever granted');
    }

    public function test_configuration_requires_admin_panel_plus_captain5_admin(): void
    {
        $access = app(Captain5Access::class);
        self::assertTrue($access->decide($this->captain5Administrator())->canConfigure);
        self::assertTrue($access->decide($this->superAdmin())->canConfigure);

        $permissionOnly = $this->officer('Captain');
        $permissionOnly->givePermissionTo(Captain5Access::PERMISSION_ADMIN);
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($permissionOnly->fresh())->canConfigure, 'captain5.admin without the Admin Panel is not enough');

        $this->expectException(AuthorizationException::class);
        app(Captain5AdministrationService::class)->setOverride($this->officer('Division Chief'), 1, 'deny', 'Not allowed');
    }

    public function test_inactive_departed_and_pending_password_accounts_are_denied(): void
    {
        $holder = $this->officer('Captain');
        $this->seat($holder, 'Captain 5', 'A Shift');
        User::query()->whereKey($holder->id)->update(['account_status' => 'disabled']);
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($holder->fresh())->canViewPage);

        $pending = $this->officer('Captain');
        User::query()->whereKey($pending->id)->update(['must_change_password' => true]);
        self::assertFalse(app(Captain5Access::class)->decide($pending->fresh())->canViewPage);
        self::assertFalse(app(Captain5Access::class)->decide(null)->canViewPage);
    }

    public function test_the_workspace_shell_is_server_gated(): void
    {
        $this->actAs($this->officer('Firefighter'));
        $this->get('/daily/captain-5')->assertForbidden();
        $this->get('/daily/CAPTAIN-5/')->assertForbidden();
        $this->getJson('/api/captain5/workspace')->assertForbidden();
        // The station-list card probe answers without a 403 and without any Captain 5 data.
        $this->getJson('/api/captain5/summary')->assertOk()->assertExactJson(['access' => ['view' => false]]);

        $this->actAs($this->officer('Lieutenant'));
        $this->getJson('/api/captain5/workspace')->assertOk()
            ->assertJsonPath('access.view', true)
            ->assertJsonPath('access.act', false);
    }
}
