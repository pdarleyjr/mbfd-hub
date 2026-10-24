<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5\Admin;

use App\Filament\Admin\Pages\Captain5Operations;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Captain5\Captain5ActingService;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Captain5Access;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * The "Acting Captain 5 requests" section of the Admin Panel page: who is shown as the shift's
 * Captain 5, the requests waiting for a decision, and the decided history. Approval is display
 * only, so these tests also check that nobody's access to the Captain 5 page changes.
 */
final class Captain5ActingRequestsSectionTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_bid_holder_is_shown_when_nobody_is_acting(): void
    {
        $holder = $this->officer('Captain', 'Bid Holder Bo');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $this->actAs($this->superAdmin());

        Livewire::test(Captain5Operations::class)
            ->assertSee('Shown as Captain 5 now')->assertSee('Bid Holder Bo')->assertSee('Captain 5 bid seat')->assertSee('(A shift)')
            ->assertSee('No requests are waiting.')->assertSee('No request has been decided yet.');
    }

    public function test_nobody_is_shown_when_there_is_no_bid_holder_and_no_acting_request(): void
    {
        $this->actAs($this->superAdmin());

        Livewire::test(Captain5Operations::class)->assertSee('No one is shown as Captain 5 right now');
    }

    public function test_a_pending_request_lists_who_asked_how_long_and_when(): void
    {
        $member = $this->officer('Captain', 'Acting Avery');
        $this->requestActing($member, '24h');
        $this->actAs($this->captain5Administrator());

        Livewire::test(Captain5Operations::class)
            ->assertSee('Waiting for a decision')
            ->assertSee('Acting Avery')->assertSee('asks to be shown as acting Captain 5')
            ->assertSee('24 hours')->assertSee('A shift')->assertSee('Asked by Acting Avery')
            ->assertSee('Approve')->assertSee('Not approved')
            ->assertDontSee('No requests are waiting.');
    }

    public function test_approve_with_an_optional_note_shows_the_member_without_changing_anyones_access(): void
    {
        $holder = $this->officer('Captain', 'Bid Holder Bo');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $member = $this->officer('Firefighter', 'Acting Avery');
        $admin = $this->captain5Administrator();
        $member->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->forgetCaptain5Memo();
        $request = $this->requestActing($member->fresh(), '24h');
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class)->assertSee('Bid Holder Bo');
        $page->callAction('approveActing', ['note' => 'Covering for Captain Bo'], ['request' => $request->getKey()])->assertHasNoActionErrors();

        $request->refresh();
        self::assertSame(Captain5ActingRequest::APPROVED, $request->status);
        self::assertSame((int) $admin->id, (int) $request->decided_by_user_id);
        self::assertSame('Covering for Captain Bo', $request->decision_note);
        self::assertNotNull($request->starts_at);

        $this->forgetCaptain5Memo();
        $onDuty = app(Captain5DutyRoster::class)->onDuty();
        self::assertSame(OnDutyCaptain5::SOURCE_ACTING, $onDuty->source);
        self::assertSame((int) $member->employee_profile_id, (int) $onDuty->employee?->getKey());
        // Display only: the bid holder keeps the whole page, and the acting member's access is unchanged.
        self::assertTrue(app(Captain5Access::class)->decide($holder->fresh())->canAct);
        self::assertTrue(app(Captain5Access::class)->decide($member->fresh())->canAct);

        $page->assertSee('Acting Captain 5 (approved request)')->assertSee('Acting until')
            ->assertSee('No requests are waiting.')
            ->assertSee('Recent decisions')->assertSee('Approved')->assertSee('Covering for Captain Bo')->assertSee('Showing now');
    }

    public function test_approve_without_a_note_is_allowed(): void
    {
        $member = $this->officer('Captain', 'Quiet Quinn');
        $request = $this->requestActing($member, '12h');
        $this->actAs($this->captain5Administrator());

        Livewire::test(Captain5Operations::class)
            ->callAction('approveActing', [], ['request' => $request->getKey()])->assertHasNoActionErrors();

        self::assertSame(Captain5ActingRequest::APPROVED, $request->fresh()->status);
        self::assertNull($request->fresh()->decision_note);
    }

    public function test_not_approved_needs_a_note_then_keeps_the_request_as_history(): void
    {
        $holder = $this->officer('Captain', 'Bid Holder Bo');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $member = $this->officer('Captain', 'Declined Dana');
        $request = $this->requestActing($member, '24h');
        $admin = $this->captain5Administrator();
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class);
        $page->callAction('rejectActing', ['note' => ''], ['request' => $request->getKey()])->assertHasActionErrors(['note']);
        self::assertSame(Captain5ActingRequest::PENDING, $request->fresh()->status);

        $page->callAction('rejectActing', ['note' => 'The Captain is back tomorrow'], ['request' => $request->getKey()])->assertHasNoActionErrors();
        $request->refresh();
        self::assertSame(Captain5ActingRequest::REJECTED, $request->status);
        self::assertSame('The Captain is back tomorrow', $request->decision_note);
        self::assertSame((int) $admin->id, (int) $request->decided_by_user_id);

        $this->forgetCaptain5Memo();
        self::assertSame(OnDutyCaptain5::SOURCE_BID, app(Captain5DutyRoster::class)->onDuty()->source, 'Not approved changes nothing on the page');
        $page->assertSee('Not approved')->assertSee('Declined Dana')->assertSee('The Captain is back tomorrow')->assertSee('Captain 5 Admin')
            ->assertSee('No requests are waiting.');
    }

    public function test_an_administrator_cannot_decide_their_own_request_but_a_super_administrator_can(): void
    {
        $admin = $this->captain5Administrator();
        $own = $this->requestActing($admin, '24h');
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class)
            ->assertSee('Another administrator approves your own request.');
        $page->callAction('approveActing', [], ['request' => $own->getKey()])->assertNotified('Nothing was changed')->call('unmountAction');
        $page->callAction('rejectActing', ['note' => 'No'], ['request' => $own->getKey()])->assertNotified('Nothing was changed');
        self::assertSame(Captain5ActingRequest::PENDING, $own->fresh()->status);

        try {
            app(Captain5ActingService::class)->approve($admin, (int) $own->getKey());
            self::fail('The service refuses self-approval for anyone but a super administrator.');
        } catch (AuthorizationException) {
            self::assertSame(Captain5ActingRequest::PENDING, $own->fresh()->status);
        }
    }

    public function test_a_super_administrator_may_approve_their_own_request(): void
    {
        $root = $this->superAdmin();
        $employee = Employee::query()->create([
            'employee_id' => 'ROOT-9', 'name' => 'Root Nine', 'rank' => 'Firefighter', 'password' => 'x', 'must_change_password' => false,
        ]);
        $root->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        $root = $root->fresh();
        $own = $this->requestActing($root, '24h');
        $this->actAs($root);

        Livewire::test(Captain5Operations::class)->assertDontSee('Another administrator approves your own request.')
            ->callAction('approveActing', [], ['request' => $own->getKey()])->assertHasNoActionErrors();

        self::assertSame(Captain5ActingRequest::APPROVED, $own->fresh()->status);
    }

    public function test_a_request_past_its_end_time_cannot_be_approved_but_can_be_declined(): void
    {
        $member = $this->officer('Captain', 'Late Lee');
        $request = app(Captain5ActingService::class)->request($member, [
            'kind' => 'act', 'duration' => 'custom', 'ends_at' => now('America/New_York')->addMinutes(20)->format('Y-m-d H:i:s'),
        ]);
        $this->travel(30)->minutes();
        $this->forgetCaptain5Memo();
        $this->actAs($this->captain5Administrator());

        $page = Livewire::test(Captain5Operations::class)->assertSee('Late Lee')->assertSee('Past its end time');
        $page->callAction('approveActing', ['note' => 'Too late'], ['request' => $request->getKey()])->assertNotified('Nothing was changed')->call('unmountAction');
        self::assertSame(Captain5ActingRequest::PENDING, $request->fresh()->status);
        self::assertNull($request->fresh()->starts_at);

        $page->callAction('rejectActing', ['note' => 'Its end time passed before a decision'], ['request' => $request->getKey()])->assertHasNoActionErrors();
        self::assertSame(Captain5ActingRequest::REJECTED, $request->fresh()->status);
    }

    public function test_approving_a_new_request_ends_the_previous_acting_display(): void
    {
        $admin = $this->captain5Administrator();
        $first = $this->officer('Captain', 'First Acting Fran');
        $second = $this->officer('Lieutenant', 'Second Acting Sam');
        $firstRequest = $this->actingAssignment($first, $admin);
        $secondRequest = $this->requestActing($second, '24h');
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class)->assertSee('First Acting Fran');
        $page->callAction('approveActing', [], ['request' => $secondRequest->getKey()])->assertHasNoActionErrors();

        $firstRequest->refresh();
        self::assertNotNull($firstRequest->ended_at);
        self::assertSame((int) $secondRequest->getKey(), (int) $firstRequest->ended_by_request_id);
        $this->forgetCaptain5Memo();
        $onDuty = app(Captain5DutyRoster::class)->onDuty();
        self::assertSame((int) $second->employee_profile_id, (int) $onDuty->employee?->getKey());
        self::assertSame(1, Captain5ActingRequest::query()->showingAt(now(), Captain5ActingRequest::ROLE_CAPTAIN_5)->count(), 'Only one acting display at a time');

        $page->assertSee('Second Acting Sam')->assertSee('Ended early');
    }

    public function test_an_end_request_is_listed_and_approving_it_returns_the_page_to_the_bid_holder(): void
    {
        $holder = $this->officer('Captain', 'Bid Holder Bo');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $admin = $this->captain5Administrator();
        $acting = $this->officer('Captain', 'Acting Avery');
        $display = $this->actingAssignment($acting, $admin);
        $asker = $this->officer('Lieutenant', 'Ending Eli');
        $end = app(Captain5ActingService::class)->request($asker, ['kind' => 'end']);
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class)
            ->assertSee('Ending Eli asks to end the acting display')->assertSee('Acting Avery');
        $page->callAction('approveActing', ['note' => 'Captain Bo is back'], ['request' => $end->getKey()])->assertHasNoActionErrors();

        $display->refresh();
        self::assertNotNull($display->ended_at);
        self::assertSame((int) $end->getKey(), (int) $display->ended_by_request_id);
        $this->forgetCaptain5Memo();
        $onDuty = app(Captain5DutyRoster::class)->onDuty();
        self::assertSame(OnDutyCaptain5::SOURCE_BID, $onDuty->source);
        self::assertSame((int) $holder->employee_profile_id, (int) $onDuty->employee?->getKey());

        $page->assertSee('Captain 5 bid seat')->assertSee('Ending Eli: end the acting display')->assertSee('Captain Bo is back')
            ->assertSee('Ended early');
    }

    public function test_an_end_request_with_nothing_to_end_is_refused_when_asked_and_when_decided(): void
    {
        $asker = $this->officer('Lieutenant', 'Ending Eli');
        try {
            app(Captain5ActingService::class)->request($asker, ['kind' => 'end']);
            self::fail('There is no acting display to end.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('kind', $exception->errors());
        }

        // A request filed while someone was showing, decided after that display ended on its own.
        $admin = $this->captain5Administrator();
        $acting = $this->officer('Captain', 'Acting Avery');
        $this->actingAssignment($acting, $admin, now()->addMinutes(20)->toImmutable());
        $end = app(Captain5ActingService::class)->request($asker, ['kind' => 'end']);
        $this->travel(30)->minutes();
        $this->forgetCaptain5Memo();
        $this->actAs($admin);

        Livewire::test(Captain5Operations::class)
            ->callAction('approveActing', [], ['request' => $end->getKey()])->assertNotified('Nothing was changed');
        self::assertSame(Captain5ActingRequest::PENDING, $end->fresh()->status);
    }

    public function test_an_unknown_or_already_decided_request_changes_nothing(): void
    {
        $member = $this->officer('Captain', 'Decided Dee');
        $request = $this->requestActing($member, '24h');
        $this->actAs($this->captain5Administrator());

        $page = Livewire::test(Captain5Operations::class);
        $page->callAction('approveActing', [], ['request' => 987654])->assertNotified('That request is no longer here')->call('unmountAction');
        $page->callAction('rejectActing', ['note' => 'First decision'], ['request' => $request->getKey()])->assertHasNoActionErrors();
        $page->callAction('approveActing', [], ['request' => $request->getKey()])->assertNotified('Nothing was changed');

        self::assertSame(Captain5ActingRequest::REJECTED, $request->fresh()->status);
        self::assertSame(1, Captain5ActingRequest::query()->count());
    }

    public function test_recent_decisions_are_limited_to_the_last_twenty_newest_first(): void
    {
        $admin = $this->captain5Administrator();
        $this->actAs($admin);
        foreach (range(1, 22) as $n) {
            $member = $this->officer('Captain', 'Decision Member '.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
            $request = $this->requestActing($member, '24h');
            $this->travel(1)->minutes();
            app(Captain5ActingService::class)->reject($admin, (int) $request->getKey(), 'Note '.$n);
        }
        $this->forgetCaptain5Memo();

        Livewire::test(Captain5Operations::class)
            ->assertSee('Decision Member 22')->assertSee('Decision Member 03')
            ->assertDontSee('Decision Member 02')->assertDontSee('Decision Member 01');
    }

    public function test_the_section_and_its_actions_need_a_current_captain5_administrator(): void
    {
        $member = $this->officer('Captain', 'Pending Pat');
        $request = $this->requestActing($member, '24h');
        $admin = $this->captain5Administrator();
        $this->actAs($admin);
        $page = Livewire::test(Captain5Operations::class)->assertSee('Pending Pat');

        // The permission is revoked while the page is open: the next action is refused and writes nothing.
        $admin->revokePermissionTo(Captain5Access::PERMISSION_ADMIN);
        $this->forgetCaptain5Memo();
        $page->call('mountAction', 'approveActing', ['request' => $request->getKey()])->assertForbidden();
        self::assertSame(Captain5ActingRequest::PENDING, $request->fresh()->status);

        // The service refuses a caller without the permission as well.
        try {
            app(Captain5ActingService::class)->approve($admin->fresh(), (int) $request->getKey());
            self::fail('The service must refuse a caller without Captain 5 administration.');
        } catch (AuthorizationException) {
            self::assertSame(Captain5ActingRequest::PENDING, $request->fresh()->status);
        }
    }

    public function test_no_acting_row_is_read_from_the_retired_duty_assignment_table(): void
    {
        $this->officer('Captain', 'Retired Rae');
        $this->actAs($this->captain5Administrator());

        Livewire::test(Captain5Operations::class)->assertDontSee('Assign acting Captain 5')->assertDontSee('End assignment')
            ->assertDontSee('Show ended assignments');
    }

    private function requestActing(User $member, string $duration): Captain5ActingRequest
    {
        $this->forgetCaptain5Memo();

        return app(Captain5ActingService::class)->request($member, ['kind' => 'act', 'duration' => $duration]);
    }
}
