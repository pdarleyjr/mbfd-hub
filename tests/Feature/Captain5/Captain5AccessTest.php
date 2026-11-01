<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\User;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Captain5Access;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_every_officer_chief_and_administrator_can_use_the_whole_page_and_others_cannot(): void
    {
        $access = app(Captain5Access::class);
        foreach (['Lieutenant', 'Captain', 'Division Chief', 'Deputy Fire Chief', 'Fire Chief', '  captain '] as $rank) {
            $decision = $access->decide($this->officer($rank));
            self::assertTrue($decision->canViewPage, "{$rank} must open the workspace");
            self::assertTrue($decision->canAct, "{$rank} must be able to act (no duty gating)");
            self::assertTrue($decision->canDismiss, "{$rank} may close issues");
        }
        foreach (['Firefighter', 'Driver Engineer', 'EMS Coordinator', 'Chief Plans Analyst #3', 'Capt', 'Lt', 'Captain 5', ''] as $rank) {
            $decision = $access->decide($this->officer($rank));
            self::assertFalse($decision->canViewPage, "{$rank} must not open the workspace");
            self::assertFalse($decision->canAct);
        }

        $panel = $this->officer('Firefighter');
        $panel->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->forgetCaptain5Memo();
        $adminDecision = app(Captain5Access::class)->decide($panel->fresh());
        self::assertTrue($adminDecision->canViewPage, 'Members with admin rights use the page');
        self::assertTrue($adminDecision->canAct, 'and can carry out every task');
        self::assertTrue($access->decide($this->superAdmin())->canAct);
    }

    public function test_the_shift_captain5_follows_the_rotation_and_is_display_only(): void
    {
        $a = $this->officer('Captain', 'Captain A');
        $b = $this->officer('Captain', 'Captain B');
        $this->seat($a, 'Captain 5', 'A Shift');
        $this->seat($b, 'Captain 5', 'B Shift');

        $decisionA = app(Captain5Access::class)->decide($a);
        self::assertSame('A', $decisionA->onDuty->shiftLetter);
        self::assertTrue($decisionA->isShownCaptain5);
        self::assertSame('shift_captain5', $decisionA->authority());
        $decisionB = app(Captain5Access::class)->decide($b);
        self::assertFalse($decisionB->isShownCaptain5);
        self::assertTrue($decisionB->canAct, 'Off-shift seat holders still use the whole page');
        self::assertTrue($decisionB->canDismiss);

        // 07:59 the next morning is still the A duty day; 08:00 is B.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 07:59:00', 'America/New_York'));
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($a)->isShownCaptain5);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', 'America/New_York'));
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($a)->isShownCaptain5);
        self::assertTrue(app(Captain5Access::class)->decide($b)->isShownCaptain5);
    }

    public function test_an_old_approved_acting_request_changes_nothing(): void
    {
        $holder = $this->officer('Captain', 'Seat Captain');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $firefighter = $this->officer('Firefighter');
        $this->actingAssignment($firefighter, $this->captain5Administrator());

        $decision = app(Captain5Access::class)->decide($firefighter);
        self::assertFalse($decision->canViewPage);
        self::assertFalse($decision->isShownCaptain5);
        self::assertSame(OnDutyCaptain5::SOURCE_BID, $decision->onDuty->source);
        self::assertSame('Seat Captain', $decision->onDuty->toArray()['name']);
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

        $employeeLinkedSuper = $this->officer('Captain');
        $employeeLinkedSuper->assignRole(Role::findOrCreate('super_admin', 'web'));
        app(Captain5AdministrationService::class)->setOverride($this->superAdmin(), (int) $employeeLinkedSuper->employee_profile_id, 'deny', 'Test');
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($employeeLinkedSuper->fresh())->canViewPage, 'super_admin is protected recovery access');

        app(Captain5AdministrationService::class)->setOverride($admin, (int) $holder->employee_profile_id, 'automatic', 'Review complete');
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($holder)->canAct);
        $history = Captain5AccessOverride::query()->where('employee_profile_id', $holder->employee_profile_id)->get();
        self::assertCount(1, $history, 'The deny decision stays in history');
        self::assertNotNull($history->first()->superseded_at);
        self::assertDatabaseHas('security_action_events', ['action' => 'change_captain5_access', 'target_user_id' => $holder->id]);
    }

    public function test_allow_override_grants_the_whole_page_and_never_admin_panel_access(): void
    {
        $admin = $this->captain5Administrator();
        $firefighter = $this->officer('Firefighter');
        app(Captain5AdministrationService::class)->setOverride($admin, (int) $firefighter->employee_profile_id, 'allow', 'EMS logistics delegate');
        $this->forgetCaptain5Memo();

        $decision = app(Captain5Access::class)->decide($firefighter);
        self::assertTrue($decision->canAct);
        self::assertFalse($decision->canConfigure);
        self::assertFalse($firefighter->fresh()->hasCurrentAdminPanelEntitlement(), 'No Admin Panel access is ever granted');
    }

    public function test_configuration_requires_admin_panel_plus_captain5_admin(): void
    {
        $access = app(Captain5Access::class);
        self::assertTrue($access->decide($this->captain5Administrator())->canConfigure);
        self::assertTrue($access->canConfigure($this->captain5Administrator()));
        self::assertTrue($access->decide($this->superAdmin())->canConfigure);

        $permissionOnly = $this->officer('Captain');
        $permissionOnly->givePermissionTo(Captain5Access::PERMISSION_ADMIN);
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($permissionOnly->fresh())->canConfigure, 'captain5.admin without the Admin Panel is not enough');
        self::assertFalse(app(Captain5Access::class)->canConfigure($permissionOnly->fresh()));

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
            ->assertJsonPath('access.act', true)
            ->assertJsonMissingPath('access.on_duty')
            ->assertJsonMissingPath('assignments');
    }
}
