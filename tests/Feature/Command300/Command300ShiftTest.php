<?php

declare(strict_types=1);

namespace Tests\Feature\Command300;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Command300Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** The shift's 300 (Division Chief) on the 300 page and card: the TeleStaff roster, else the bid seat. */
final class Command300ShiftTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const SHIFT = '/api/command-300/shift';

    private User $chiefA;

    private User $chiefB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day, 10:00 ET
        $this->chiefA = $this->officer('Division Chief', 'Alpha Chief');
        $this->seat($this->chiefA, Command300Access::COMMAND_UNIT_LABEL, 'A Shift');
        $this->chiefB = $this->officer('Division Chief', 'Bravo Chief');
        $this->seat($this->chiefB, Command300Access::COMMAND_UNIT_LABEL, 'B Shift');
        $this->admin = $this->captain5Administrator();
    }

    public function test_the_shift_300_is_the_bid_holder_for_the_shift_on_duty_and_changes_at_0800(): void
    {
        $viewer = $this->officer('Captain', 'Viewing Captain');
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()
            ->assertJsonPath('on_duty.shift', 'A')
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_BID)
            ->assertJsonPath('on_duty.name', 'Alpha Chief')
            ->assertJsonPath('on_duty.rank', 'Division Chief');

        // The session expires when the clock jumps; sign in again after each jump.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 07:59:00', 'America/New_York'));
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.name', 'Alpha Chief');
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', 'America/New_York'));
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.shift', 'B')->assertJsonPath('on_duty.name', 'Bravo Chief');

        // C shift has no 300 seat: an explicit empty state, never a guess.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'America/New_York'));
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.shift', 'C')
            ->assertJsonPath('on_duty.resolved', false)->assertJsonPath('on_duty.name', null)
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_UNKNOWN);
    }

    public function test_with_the_card_display_off_the_answer_has_exactly_todays_shape_and_ignores_the_roster(): void
    {
        $rosterChief = $this->officer('Division Chief', 'Roster Chief');
        DailyRosterSnapshotFactory::publish('2026-10-20', ['Suppression / Division Chief 300' => [['employee_id' => $rosterChief->employee_id, 'rank' => 'DC']]]);
        config()->set('daily_roster.card_display_enabled', false);

        $this->actAs($this->officer('Captain', 'Viewing Captain'));
        $onDuty = $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.name', 'Alpha Chief')->json('on_duty');

        self::assertSame(['duty_date', 'shift', 'source', 'resolved', 'name', 'rank', 'acting_until', 'acting_request_id'], array_keys($onDuty));
    }

    public function test_the_card_shows_the_roster_and_300_access_still_follows_rank_seat_and_grants(): void
    {
        $rosterChief = $this->officer('Division Chief', 'Roster Chief');
        DailyRosterSnapshotFactory::publish('2026-10-20', ['Suppression / Division Chief 300' => [['employee_id' => $rosterChief->employee_id, 'rank' => 'DC']]]);
        config()->set('daily_roster.card_display_enabled', true);

        $this->actAs($this->officer('Captain', 'Charlie Captain'));
        $this->getJson(self::SHIFT)->assertOk()
            ->assertJsonPath('on_duty.source', 'telestaff')
            ->assertJsonPath('on_duty.name', 'Roster Chief')
            ->assertJsonPath('on_duty.rank', 'Division Chief')
            ->assertJsonPath('on_duty.freshness', 'current')
            ->assertJsonPath('on_duty.identity', 'linked')
            ->assertJsonPath('on_duty.reason', null);

        self::assertSame(OnDutyCaptain5::SOURCE_BID, app(Captain5DutyRoster::class)->command300OnDuty()->source);
    }

    public function test_a_conflict_shows_nobody_and_the_seat_answer_is_untouched(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-20', ['Suppression / Division Chief 300' => [
            ['employee_id' => '70001', 'rank' => 'DC'],
            ['employee_id' => '70002', 'rank' => 'DC', 'seat' => 1],
        ]]);
        config()->set('daily_roster.card_display_enabled', true);

        $this->actAs($this->officer('Captain', 'Viewing Captain'));
        $this->getJson(self::SHIFT)->assertOk()
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_UNKNOWN)
            ->assertJsonPath('on_duty.resolved', false)
            ->assertJsonPath('on_duty.name', null)
            ->assertJsonPath('on_duty.reason', 'conflict')
            ->assertJsonPath('on_duty.shift', 'A');

        self::assertSame('Alpha Chief', app(Captain5DutyRoster::class)->command300OnDuty()->toArray()['name']);
    }

    public function test_nobody_can_ask_to_act_any_more_on_either_page(): void
    {
        $this->actAs($this->admin);
        foreach (['/api/command-300/acting-requests', '/api/captain5/acting-requests', '/api/command-300/acting-requests/1/approve', '/api/captain5/acting-requests/1/approve'] as $gone) {
            $this->postJson($gone, ['kind' => 'act', 'duration' => '12h'])->assertNotFound();
        }

        self::assertSame(0, Captain5ActingRequest::query()->count());
    }

    public function test_an_app_still_running_the_previous_page_build_is_told_it_cannot_ask(): void
    {
        $this->actAs($this->admin);

        $this->getJson(self::SHIFT)->assertOk()
            ->assertJsonPath('acting.can_request', false)
            ->assertJsonPath('acting.can_approve', false)
            ->assertJsonPath('acting.mine', null)
            ->assertJsonPath('acting.pending', [])
            ->assertJsonPath('on_duty.acting_until', null)
            ->assertJsonPath('on_duty.acting_request_id', null);
        $this->getJson('/api/captain5/workspace')->assertOk()
            ->assertJsonPath('acting.can_request', false)
            ->assertJsonPath('acting.pending', []);
    }

    public function test_an_old_approved_acting_request_changes_nothing_and_stays_as_history(): void
    {
        $firefighter = $this->officer('Firefighter', 'Old Request');
        $old = Captain5ActingRequest::query()->create([
            'kind' => 'act', 'role' => 'command_300', 'employee_profile_id' => $firefighter->employee_profile_id,
            'requested_by_user_id' => $this->admin->id, 'duration' => '24h', 'shift_letter' => 'A',
            'ends_at' => now()->addHours(6), 'status' => 'approved', 'decided_by_user_id' => $this->admin->id, 'decided_at' => now(), 'starts_at' => now(),
        ]);
        $this->forgetCaptain5Memo();

        self::assertSame('Alpha Chief', app(Captain5DutyRoster::class)->command300OnDuty()->toArray()['name']);
        self::assertFalse(app(Command300Access::class)->allows($firefighter->fresh()));
        self::assertNotNull($old->fresh());
    }
}
