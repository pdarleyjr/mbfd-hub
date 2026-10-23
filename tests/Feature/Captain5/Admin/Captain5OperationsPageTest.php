<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5\Admin;

use App\Filament\Admin\Pages\Captain5Operations;
use App\Models\ApparatusDefect;
use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5DutyAssignment;
use App\Models\Captain5\Captain5ItemClassification;
use App\Models\User;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Security\Captain5Access;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

final class Captain5OperationsPageTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const COOLER_E2 = 'comp_r4-item-1';       // Engine 2 Water Cooler/Cups (config: ambiguous)

    private const LIFEPAK_E2 = 'comp_r1-item-1';       // Engine 2 Life-Pak (config: medical)

    private const TOUGHBOOK_E2 = 'front_cab-item-1';   // Engine 2 Toughbook (reviewed: not medical)

    private const RESCUE_ITEM = 'compartment_c-item-3';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ------------------------------------------------------------------ access

    public function test_super_admin_and_captain5_administrator_open_the_page(): void
    {
        foreach ([$this->superAdmin(), $this->captain5Administrator()] as $admin) {
            $this->actAs($admin);
            $this->get('/admin/captain5-operations')->assertOk()
                ->assertSee('Captain 5 Operations')->assertSee('Who has access')->assertSee('Medical scope review');
            self::assertTrue(Captain5Operations::canAccess());
        }
    }

    public function test_everyone_else_is_refused_server_side(): void
    {
        $plainAdmin = $this->officer('Firefighter', 'Plain Admin');
        $plainAdmin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $captain = $this->officer('Captain', 'Plain Captain');
        $captain->givePermissionTo(Permission::findOrCreate(Captain5Access::PERMISSION_ADMIN, 'web')); // permission without Admin Panel entitlement
        $firefighter = $this->officer('Firefighter', 'Plain Firefighter');

        foreach ([$plainAdmin, $captain, $firefighter, $this->officer('Captain', 'Rank Only')] as $user) {
            $this->forgetCaptain5Memo();
            $this->actAs($user->fresh());
            self::assertFalse(Captain5Operations::canAccess(), $user->name);
            $this->get('/admin/captain5-operations')->assertForbidden();
            Livewire::test(Captain5Operations::class)->assertForbidden();
        }
    }

    public function test_the_page_sits_in_the_fleet_navigation_group(): void
    {
        $this->actAs($this->superAdmin());

        self::assertSame('Fleet', Captain5Operations::getNavigationGroup());
        self::assertSame('Captain 5 Operations', Captain5Operations::getNavigationLabel());
        self::assertSame('captain5-operations', Captain5Operations::getSlug());
        $group = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Fleet');
        self::assertNotNull($group);
        self::assertContains('Captain 5 Operations', collect($group->getItems())->map(fn ($item) => $item->getLabel())->all());
    }

    public function test_a_denied_administrator_and_a_later_revocation_lose_the_page_on_the_next_request(): void
    {
        $root = $this->superAdmin();
        $admin = $this->captain5Administrator();
        $this->actAs($admin);
        $page = Livewire::test(Captain5Operations::class)->assertOk();

        // Revoking the permission makes the already-open page refuse its next interaction.
        $admin->revokePermissionTo(Captain5Access::PERMISSION_ADMIN);
        $this->forgetCaptain5Memo();
        $page->call('toggleOverrideHistory')->assertForbidden();

        // A deny decision also removes configuration rights from a Captain 5 administrator.
        $other = $this->captain5Administrator();
        app(\App\Services\Captain5\Captain5AdministrationService::class)->setOverride($root, (int) $other->employee_profile_id, 'deny', 'Removed from Captain 5 duties');
        $this->forgetCaptain5Memo();
        $this->actAs($other->fresh());
        self::assertFalse(Captain5Operations::canAccess());
        $this->get('/admin/captain5-operations')->assertForbidden();
    }

    // ------------------------------------------------------- who has access

    public function test_member_lookup_explains_the_decision_in_plain_words(): void
    {
        $seatHolder = $this->officer('Captain', 'Alpha Seatholder');
        $this->seat($seatHolder, 'Captain 5', 'A Shift');
        $firefighter = $this->officer('Firefighter', 'Plain Probie');
        $denied = $this->officer('Lieutenant', 'Denied Lieutenant');
        $root = $this->superAdmin();
        $this->actAs($root);
        app(\App\Services\Captain5\Captain5AdministrationService::class)->setOverride($root, (int) $denied->employee_profile_id, 'deny', 'Reassigned to training');
        $this->forgetCaptain5Memo();

        $page = Livewire::test(Captain5Operations::class);
        $page->set('memberSearch', 'Alpha Seat')->assertSee('Alpha Seatholder')
            ->call('chooseMember', (int) $seatHolder->employee_profile_id)
            ->assertSee('Holds the Captain 5 seat on A shift')
            ->assertSee('On-duty Captain 5 authority right now.')
            ->assertSee('Act on issues')
            ->assertSee('No administrator choice is set');

        $page->call('chooseMember', (int) $firefighter->employee_profile_id)
            ->assertSee('No Captain 5 access. This member does not qualify by rank, seat or assignment.');

        $page->call('chooseMember', (int) $denied->employee_profile_id)
            ->assertSee('Denied by an administrator')
            ->assertSee('Reassigned to training');

    }

    public function test_lookup_for_a_super_administrator_says_the_account_is_protected(): void
    {
        $root = $this->superAdmin();
        $employee = \App\Models\Employee::query()->create([
            'employee_id' => 'ROOT-1', 'name' => 'Root Recovery', 'rank' => 'Firefighter', 'password' => 'x', 'must_change_password' => false,
        ]);
        $root->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        $this->actAs($root->fresh());

        Livewire::test(Captain5Operations::class)
            ->call('chooseMember', (int) $employee->id)
            ->assertSee('protected recovery accounts')
            ->assertSee('Super administrator');
    }

    // ------------------------------------------------------- access overrides

    public function test_set_access_persists_history_and_audit_and_lists_the_override(): void
    {
        $admin = $this->captain5Administrator();
        $member = $this->officer('Firefighter', 'Delegate Dana');
        $this->actAs($admin);

        Livewire::test(Captain5Operations::class)
            ->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'allow_act', 'reason' => 'Covers EMS duties this month'])
            ->assertHasNoActionErrors()
            ->assertSee('Delegate Dana')->assertSee('Allow, view and act')->assertSee('Covers EMS duties this month')->assertSee('Captain 5 Admin');

        $override = Captain5AccessOverride::query()->current()->where('employee_profile_id', $member->employee_profile_id)->sole();
        self::assertSame('allow', $override->effect);
        self::assertSame('act', $override->level);
        self::assertSame((int) $admin->id, (int) $override->set_by_user_id);
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($member->fresh())->canAct);
        self::assertDatabaseHas('security_action_events', [
            'actor_user_id' => $admin->id, 'target_user_id' => $member->id, 'action' => 'change_captain5_access', 'reason' => 'Covers EMS duties this month',
        ]);
    }

    public function test_changing_access_keeps_history_and_automatic_removes_the_override(): void
    {
        $admin = $this->captain5Administrator();
        $member = $this->officer('Firefighter', 'History Harper');
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class);
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'allow_view', 'reason' => 'First look'])->assertHasNoActionErrors();
        $this->forgetCaptain5Memo();
        $page->callAction('changeAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'allow_act', 'reason' => 'Now acts'], ['employee' => (int) $member->employee_profile_id])->assertHasNoActionErrors();
        $this->forgetCaptain5Memo();
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'automatic', 'reason' => 'Back to automatic'])->assertHasNoActionErrors();

        self::assertSame(0, Captain5AccessOverride::query()->current()->where('employee_profile_id', $member->employee_profile_id)->count());
        self::assertSame(2, Captain5AccessOverride::query()->where('employee_profile_id', $member->employee_profile_id)->count(), 'History rows are kept');
        self::assertSame(3, DB::table('security_action_events')->where('action', 'change_captain5_access')->count());

        $page->assertSee('No access choices are set')
            ->call('toggleOverrideHistory')
            ->assertSee('History Harper: Allow, view only.')->assertSee('First look')->assertSee('History Harper: Allow, view and act.')->assertSee('Ended');
    }

    public function test_deny_needs_confirmation_and_is_never_applied_to_protected_or_own_accounts(): void
    {
        $admin = $this->captain5Administrator();
        $member = $this->officer('Lieutenant', 'Lt Denied');
        $root = $this->superAdmin();
        $rootEmployee = \App\Models\Employee::query()->create([
            'employee_id' => 'ROOT-2', 'name' => 'Root Two', 'rank' => 'Firefighter', 'password' => 'x', 'must_change_password' => false,
        ]);
        $root->forceFill(['employee_profile_id' => $rootEmployee->id, 'employee_id' => $rootEmployee->employee_id])->save();
        $this->actAs($admin);
        $page = Livewire::test(Captain5Operations::class);

        // No confirmation: refused, nothing written.
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'deny', 'reason' => 'Nope'])
            ->assertHasActionErrors(['confirm_deny']);
        self::assertSame(0, Captain5AccessOverride::query()->count());

        // Confirmed deny works and removes the rank-based access.
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'deny', 'confirm_deny' => true, 'reason' => 'Under review'])
            ->assertHasNoActionErrors();
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($member->fresh())->canViewData);
        $page->assertSee('Lt Denied')->assertSee('Deny');

        // Super administrator targets cannot be denied.
        $page->callAction('setAccess', ['employee_profile_id' => (int) $rootEmployee->id, 'choice' => 'deny', 'confirm_deny' => true, 'reason' => 'Try'])
            ->assertHasActionErrors(['choice']);
        self::assertSame(0, Captain5AccessOverride::query()->where('employee_profile_id', $rootEmployee->id)->count());

        // Own access is changed by someone else.
        $page->callAction('setAccess', ['employee_profile_id' => (int) $admin->employee_profile_id, 'choice' => 'deny', 'confirm_deny' => true, 'reason' => 'Self'])
            ->assertHasActionErrors(['employee_profile_id']);
        $page->callAction('setAccess', ['employee_profile_id' => (int) $admin->employee_profile_id, 'choice' => 'allow_act', 'reason' => 'Self grant'])
            ->assertHasActionErrors(['employee_profile_id']);
        self::assertSame(0, Captain5AccessOverride::query()->where('employee_profile_id', $admin->employee_profile_id)->count());
    }

    public function test_set_access_requires_a_choice_a_reason_and_a_real_member(): void
    {
        $this->actAs($this->captain5Administrator());
        $member = $this->officer('Firefighter', 'Needs Reason');
        $page = Livewire::test(Captain5Operations::class);

        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'allow_view', 'reason' => ''])->assertHasActionErrors(['reason']);
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => null, 'reason' => 'x'])->assertHasActionErrors(['choice']);
        $page->callAction('setAccess', ['employee_profile_id' => 987654, 'choice' => 'allow_view', 'reason' => 'Ghost'])->assertHasActionErrors(['employee_profile_id']);
        self::assertSame(0, Captain5AccessOverride::query()->count());
    }

    // ------------------------------------------------------ acting Captain 5

    public function test_assign_and_end_an_acting_captain5_with_history_and_audit(): void
    {
        $admin = $this->captain5Administrator();
        $acting = $this->officer('Firefighter', 'Acting Avery');
        $this->actAs($admin);
        $today = now('America/New_York')->format('Y-m-d');

        $page = Livewire::test(Captain5Operations::class)->assertSee('No acting assignments');
        $page->callAction('assignActing', [
            'employee_profile_id' => (int) $acting->employee_profile_id, 'duty_date' => $today,
            'reason' => 'Out of class coverage for Captain 5', 'client_request_id' => 'assign-0001-0001',
        ])->assertHasNoActionErrors();

        $assignment = Captain5DutyAssignment::query()->sole();
        self::assertSame((int) $acting->employee_profile_id, (int) $assignment->employee_profile_id);
        self::assertSame('Out of class coverage for Captain 5', $assignment->reason);
        self::assertSame((int) $admin->id, (int) $assignment->assigned_by_user_id);
        self::assertSame('A', $assignment->shift_letter);
        self::assertNull($assignment->revoked_at);
        self::assertDatabaseHas('security_action_events', ['action' => 'captain5_acting_assigned', 'target_user_id' => $acting->id]);

        $page->assertSee('Acting Avery')->assertSee('Out of class coverage for Captain 5')
            ->assertSee('Acting assignment'); // on-duty source
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5Access::class)->decide($acting->fresh())->hasOnDutyAuthority);

        // The same duty day cannot be assigned twice.
        $page->callAction('assignActing', [
            'employee_profile_id' => (int) $this->officer('Firefighter', 'Second Sam')->employee_profile_id, 'duty_date' => $today,
            'reason' => 'Duplicate', 'client_request_id' => 'assign-0002-0002',
        ])->assertHasActionErrors(['duty_date'])->call('unmountAction');
        self::assertSame(1, Captain5DutyAssignment::query()->count());

        // Ending requires a reason, then ends it for good and keeps the row.
        $page->callAction('endAssignment', ['reason' => ''], ['assignment' => $assignment->id])->assertHasActionErrors(['reason']);
        self::assertNull($assignment->fresh()->revoked_at);
        $page->callAction('endAssignment', ['reason' => 'Captain returned early'], ['assignment' => $assignment->id])->assertHasNoActionErrors();
        $assignment->refresh();
        self::assertNotNull($assignment->revoked_at);
        self::assertSame('Captain returned early', $assignment->revocation_reason);
        self::assertSame((int) $admin->id, (int) $assignment->revoked_by_user_id);
        self::assertDatabaseHas('security_action_events', ['action' => 'captain5_acting_revoked', 'target_user_id' => $acting->id]);
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5Access::class)->decide($acting->fresh())->hasOnDutyAuthority);

        $page->assertSee('No acting assignments')
            ->call('toggleEndedAssignments')->assertSee('Captain returned early');
    }

    public function test_assignment_refuses_past_far_future_and_unknown_members(): void
    {
        $this->actAs($this->captain5Administrator());
        $member = $this->officer('Firefighter', 'Bad Dates');
        $page = Livewire::test(Captain5Operations::class);

        foreach (['2026-10-01', '2026-12-31'] as $date) {
            $page->callAction('assignActing', [
                'employee_profile_id' => (int) $member->employee_profile_id, 'duty_date' => $date, 'reason' => 'Out of range', 'client_request_id' => 'range-'.$date.'-xx',
            ])->assertHasActionErrors(['duty_date'])->call('unmountAction');
        }
        $page->callAction('assignActing', [
            'employee_profile_id' => 987654, 'duty_date' => now('America/New_York')->format('Y-m-d'), 'reason' => 'Ghost', 'client_request_id' => 'ghost-0000-0001',
        ])->assertHasActionErrors(['employee_profile_id']);
        self::assertSame(0, Captain5DutyAssignment::query()->count());
    }

    public function test_the_on_duty_bid_holder_is_shown_without_an_assignment(): void
    {
        $holder = $this->officer('Captain', 'Bid Holder Bo');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $this->actAs($this->superAdmin());

        Livewire::test(Captain5Operations::class)->assertSee('Bid Holder Bo')->assertSee('Captain 5 bid seat')->assertSee('(A shift)');
    }

    // ----------------------------------------------------- medical scope review

    public function test_review_lists_ambiguous_and_unclassified_open_defect_items_and_hides_the_rest(): void
    {
        $member = $this->newMember('Reporter Rae');
        $this->reportMissing($this->unit, $member, [self::COOLER_E2, self::LIFEPAK_E2, self::TOUGHBOOK_E2]);
        $this->reportMissing($this->rescue, $member, [self::RESCUE_ITEM]);
        // An inventory addition that did not exist when the template was reviewed.
        ApparatusDefect::query()->create([
            'apparatus_id' => $this->unit->id, 'compartment' => 'Compartment 9', 'item' => 'Inventory Added Monitor', 'item_key' => 'inv-c9-added-monitor',
            'status' => 'open', 'issue_type' => 'missing', 'reported_date' => now(), 'resolved' => false,
        ]);
        $this->forgetCaptain5Memo();
        $this->actAs($this->superAdmin());

        $page = Livewire::test(Captain5Operations::class)
            ->assertSee('Water Cooler/Cups')->assertSee('Medical role depends on how the department uses it')
            ->assertSee('Inventory Added Monitor')->assertSee('Added after the last medical review')
            ->assertSee('1 open issue waiting')
            ->assertDontSee('Life-Pak')   // mapped medical
            ->assertDontSee('Toughbook'); // reviewed not medical
        $page->assertSee('waiting for a decision');

        // Ambiguous items from the other Engine/Ladder templates are queued even with no open issue.
        $page->assertSee('Sanizide')->assertSee('Jumbo Stretcher');
        // Rescue is always in scope and never listed.
        $page->assertDontSee('Bolt Cutters');
    }

    public function test_classify_persists_the_decision_and_moves_the_item_out_of_the_queue(): void
    {
        $admin = $this->captain5Administrator();
        $member = $this->newMember('Reporter Rae');
        [$defect] = $this->reportMissing($this->unit, $member, [self::COOLER_E2]);
        $captain = $this->officer('Captain', 'Captain Alpha');
        $this->seat($captain, 'Captain 5', 'A Shift');
        $this->forgetCaptain5Memo();
        $this->actAs($admin);
        self::assertFalse(app(Captain5IssueScope::class)->inScope($defect->fresh()), 'Ambiguous items are hidden from Captain 5 first');

        $page = Livewire::test(Captain5Operations::class)->assertSee('Water Cooler/Cups');
        $page->callAction('classifyItem', ['classification' => 'medical', 'reason' => 'Stocked for patient care on the rescue side'], ['type' => 'engine2', 'key' => self::COOLER_E2])
            ->assertHasNoActionErrors();

        $row = Captain5ItemClassification::query()->where('checklist_type', 'engine2')->where('item_key', self::COOLER_E2)->sole();
        self::assertSame('medical', $row->classification);
        self::assertSame('Water Cooler/Cups', $row->item_name);
        self::assertSame((int) $admin->id, (int) $row->decided_by_user_id);
        self::assertSame('Stocked for patient care on the rescue side', $row->reason);
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5IssueScope::class)->inScope($defect->fresh()), 'Now visible to Captain 5');

        $page->assertSee('Decisions already made')->assertSee('Medical (Captain 5 handles these issues)')
            ->assertSee('Stocked for patient care on the rescue side')->assertSee('Captain 5 Admin');

        // Changing a decision keeps the earlier one as history and takes the item back out of scope.
        $page->callAction('changeClassification', ['classification' => 'not_medical', 'reason' => 'Facilities item after review'], ['type' => 'engine2', 'key' => self::COOLER_E2])
            ->assertHasNoActionErrors();
        self::assertSame(2, Captain5ItemClassification::query()->count(), 'The earlier decision is kept');
        self::assertSame('not_medical', Captain5ItemClassification::query()->current()->sole()->classification);
        self::assertNotNull(Captain5ItemClassification::query()->where('classification', 'medical')->sole()->superseded_at);
        $this->forgetCaptain5Memo();
        self::assertFalse(app(Captain5IssueScope::class)->inScope($defect->fresh()));
    }

    public function test_classify_refuses_bad_input_and_unknown_items(): void
    {
        $this->actAs($this->captain5Administrator());
        $page = Livewire::test(Captain5Operations::class);

        $page->callAction('classifyItem', ['classification' => 'medical', 'reason' => ''], ['type' => 'engine2', 'key' => self::COOLER_E2])->assertHasActionErrors(['reason']);
        $page->callAction('classifyItem', ['classification' => 'maybe', 'reason' => 'Unsure'], ['type' => 'engine2', 'key' => self::COOLER_E2]);
        // A made-up key, a Rescue key and a mapped-medical key are not in the review list.
        foreach ([['engine2', 'made-up-key'], ['rescue', self::RESCUE_ITEM], ['engine2', self::LIFEPAK_E2]] as [$type, $key]) {
            $page->callAction('classifyItem', ['classification' => 'medical', 'reason' => 'Not allowed'], ['type' => $type, 'key' => $key]);
        }
        self::assertSame(0, Captain5ItemClassification::query()->count());
    }

    public function test_a_page_action_from_an_unauthorized_user_writes_nothing(): void
    {
        $plain = $this->officer('Firefighter', 'Plain Admin');
        $plain->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $member = $this->officer('Firefighter', 'Target Tom');
        $this->actAs($plain->fresh());

        Livewire::test(Captain5Operations::class)->assertForbidden();
        self::assertSame(0, Captain5AccessOverride::query()->count());
        self::assertSame(0, Captain5DutyAssignment::query()->count());
        self::assertNotNull($member);
        self::assertInstanceOf(User::class, $plain);
    }
}
