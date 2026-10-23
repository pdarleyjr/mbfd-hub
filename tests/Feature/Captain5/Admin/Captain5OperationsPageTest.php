<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5\Admin;

use App\Filament\Admin\Pages\Captain5Operations;
use App\Models\ApparatusDefect;
use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Captain5\Captain5ItemClassification;
use App\Models\User;
use App\Services\Captain5\Captain5AccessExplainer;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Security\Captain5Access;
use App\Services\Security\Captain5AccessDecision;
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
            ->assertSee('Can use the whole Captain 5 page')
            ->assertSee('is shown as the shift')
            ->assertSee('Act on issues')
            ->assertSee('No administrator choice is set');

        $page->call('chooseMember', (int) $firefighter->employee_profile_id)
            ->assertSee('No Captain 5 access. This member does not qualify by rank, seat or administrator access.');

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
            ->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'allow', 'reason' => 'Covers EMS duties this month'])
            ->assertHasNoActionErrors()
            ->assertSee('Delegate Dana')->assertSee('Allow')->assertSee('Covers EMS duties this month')->assertSee('Captain 5 Admin');

        $override = Captain5AccessOverride::query()->current()->where('employee_profile_id', $member->employee_profile_id)->sole();
        self::assertSame('allow', $override->effect);
        self::assertSame('act', $override->level);
        self::assertSame((int) $admin->id, (int) $override->set_by_user_id);
        $this->forgetCaptain5Memo();
        $decision = app(Captain5Access::class)->decide($member->fresh());
        self::assertTrue($decision->canViewPage && $decision->canViewData && $decision->canAct, 'Allow means the whole page');
        self::assertContains(Captain5AccessDecision::SOURCE_EXPLICIT_ALLOW, $decision->sources);
        self::assertDatabaseHas('security_action_events', [
            'actor_user_id' => $admin->id, 'target_user_id' => $member->id, 'action' => 'change_captain5_access', 'reason' => 'Covers EMS duties this month',
        ]);
    }

    public function test_only_automatic_allow_and_deny_are_offered_and_the_old_levels_are_refused(): void
    {
        $this->actAs($this->captain5Administrator());
        $member = $this->officer('Firefighter', 'Old Levels');
        $page = Livewire::test(Captain5Operations::class);

        self::assertSame(['automatic', 'allow', 'deny'], array_keys(Captain5AccessExplainer::CHOICE_LABELS));
        self::assertSame('Allow: can use the Captain 5 page', Captain5AccessExplainer::CHOICE_LABELS['allow']);
        foreach (['allow_view', 'allow_act'] as $old) {
            $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => $old, 'reason' => 'Old level'])
                ->assertHasActionErrors(['choice'])->call('unmountAction');
        }
        self::assertSame(0, Captain5AccessOverride::query()->count());
    }

    public function test_a_stored_view_only_allow_row_reads_as_a_full_allow(): void
    {
        $admin = $this->captain5Administrator();
        $member = $this->officer('Firefighter', 'Stored View');
        Captain5AccessOverride::query()->create([
            'employee_profile_id' => $member->employee_profile_id, 'effect' => Captain5AccessOverride::EFFECT_ALLOW,
            'level' => Captain5AccessOverride::LEVEL_VIEW, 'reason' => 'Granted before duty gating was removed', 'set_by_user_id' => $admin->id,
        ]);
        $this->forgetCaptain5Memo();
        $this->actAs($admin);

        self::assertSame('allow', app(Captain5AccessExplainer::class)->currentChoice((int) $member->employee_profile_id));
        self::assertTrue(app(Captain5Access::class)->decide($member->fresh())->canAct);
        Livewire::test(Captain5Operations::class)->assertSee('Stored View')->assertSee('Granted before duty gating was removed')
            ->call('chooseMember', (int) $member->employee_profile_id)->assertSee('Allowed by an administrator')->assertSee('Administrator choice: Allow.');
    }

    public function test_changing_access_keeps_history_and_automatic_removes_the_override(): void
    {
        $admin = $this->captain5Administrator();
        $member = $this->officer('Firefighter', 'History Harper');
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class);
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'allow', 'reason' => 'First look'])->assertHasNoActionErrors();
        $this->forgetCaptain5Memo();
        $page->callAction('changeAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'deny', 'confirm_deny' => true, 'reason' => 'Second thoughts'], ['employee' => (int) $member->employee_profile_id])->assertHasNoActionErrors();
        $this->forgetCaptain5Memo();
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'automatic', 'reason' => 'Back to automatic'])->assertHasNoActionErrors();

        self::assertSame(0, Captain5AccessOverride::query()->current()->where('employee_profile_id', $member->employee_profile_id)->count());
        self::assertSame(2, Captain5AccessOverride::query()->where('employee_profile_id', $member->employee_profile_id)->count(), 'History rows are kept');
        self::assertSame(3, DB::table('security_action_events')->where('action', 'change_captain5_access')->count());

        $page->assertSee('No access choices are set')
            ->call('toggleOverrideHistory')
            ->assertSee('History Harper: Allow.')->assertSee('First look')->assertSee('History Harper: Deny.')->assertSee('Second thoughts')->assertSee('Ended');
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
        $page->callAction('setAccess', ['employee_profile_id' => (int) $admin->employee_profile_id, 'choice' => 'allow', 'reason' => 'Self grant'])
            ->assertHasActionErrors(['employee_profile_id']);
        self::assertSame(0, Captain5AccessOverride::query()->where('employee_profile_id', $admin->employee_profile_id)->count());
    }

    public function test_set_access_requires_a_choice_a_reason_and_a_real_member(): void
    {
        $this->actAs($this->captain5Administrator());
        $member = $this->officer('Firefighter', 'Needs Reason');
        $page = Livewire::test(Captain5Operations::class);

        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => 'allow', 'reason' => ''])->assertHasActionErrors(['reason']);
        $page->callAction('setAccess', ['employee_profile_id' => (int) $member->employee_profile_id, 'choice' => null, 'reason' => 'x'])->assertHasActionErrors(['choice']);
        $page->callAction('setAccess', ['employee_profile_id' => 987654, 'choice' => 'allow', 'reason' => 'Ghost'])->assertHasActionErrors(['employee_profile_id']);
        self::assertSame(0, Captain5AccessOverride::query()->count());
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
        self::assertSame(0, Captain5ActingRequest::query()->count());
        self::assertNotNull($member);
        self::assertInstanceOf(User::class, $plain);
    }
}
