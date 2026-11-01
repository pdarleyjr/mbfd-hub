<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Models\DailyRoster\RosterEvent;
use App\Models\User;
use App\Services\Captain5\Captain5AccessExplainer;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\DailyRoster\RosterStateRecorder;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\Security\Captain5Access;
use App\Services\Security\Captain5AccessDecision;
use App\Services\Security\Command300Access;
use Carbon\CarbonImmutable;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * Anyone TeleStaff lists as working the 300 or Captain 5 seat may use that page for the 08:00 to 08:00
 * duty day. Nothing is stored as a permission: every answer reads the accepted roster for the duty day on
 * the clock, so access starts and ends with it. Names are synthetic.
 */
final class RosterSeatAccessTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const TODAY = '2026-10-20'; // A shift

    private const TOMORROW = '2026-10-21'; // B shift

    private const C5 = 'Rescue Division / Captain 5';

    private const CHIEF = 'Suppression / Division Chief 300';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:30:00');
        config()->set('daily_roster.card_display_enabled', true);
        config()->set('daily_roster.seat_access_enabled', true);
        config()->set('narcotics_checks.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(User $user, array $overrides = []): array
    {
        return $overrides + ['employee_id' => $user->employee_id, 'name' => 'MEMBER, TEST', 'rank' => 'FF', 'code' => '+OT', 'category' => 'Overtime'];
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'America/New_York');
    }

    private function travelToLocal(string $local): void
    {
        $this->travelTo($this->at($local));
        $this->forgetCaptain5Memo();
    }

    private function allows300(User $user): bool
    {
        return app(Command300Access::class)->allows($user->fresh());
    }

    private function decision(User $user): Captain5AccessDecision
    {
        $this->forgetCaptain5Memo();

        return app(Captain5Access::class)->decide($user->fresh());
    }

    public function test_the_person_in_each_seat_gets_that_page_and_not_the_other(): void
    {
        $inC5 = $this->officer('Firefighter', 'In Captain Five');
        $in300 = $this->officer('Firefighter', 'In Three Hundred');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($inC5)], self::CHIEF => [$this->row($in300)]]);

        self::assertTrue($this->decision($inC5)->canViewData);
        self::assertTrue($this->decision($inC5)->canAct);
        self::assertTrue($this->decision($inC5)->has(Captain5AccessDecision::SOURCE_ROSTER_SEAT));
        self::assertFalse($this->allows300($inC5));

        self::assertSame(Command300Access::SOURCE_ROSTER, app(Command300Access::class)->source($in300->fresh()));
        self::assertFalse($this->decision($in300)->canViewData);
    }

    public function test_the_pages_themselves_open(): void
    {
        $inC5 = $this->officer('Firefighter');
        $in300 = $this->officer('Firefighter');
        $neither = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($inC5)], self::CHIEF => [$this->row($in300)]]);

        $this->actAs($inC5);
        $this->getJson('/api/captain5/workspace')->assertOk();
        $this->getJson('/api/command-300/shift')->assertForbidden();

        $this->actAs($in300);
        $this->getJson('/api/command-300/shift')->assertOk();
        $this->getJson('/api/captain5/workspace')->assertForbidden();

        $this->actAs($neither);
        $this->getJson('/api/command-300/shift')->assertForbidden();
        $this->getJson('/api/captain5/workspace')->assertForbidden();
    }

    public function test_a_split_seat_gives_both_people_the_whole_duty_day(): void
    {
        $days = $this->officer('Firefighter', 'Day Side');
        $nights = $this->officer('Firefighter', 'Night Side');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [
            $this->row($days, ['from' => '08:00', 'through' => '20:00', 'hours' => 12.0]),
            $this->row($nights, ['from' => '20:00', 'through' => '08:00', 'hours' => 12.0]),
        ]]);

        foreach (['2026-10-20 10:30', '2026-10-20 23:00', '2026-10-21 07:59'] as $moment) {
            $this->travelToLocal($moment);
            self::assertTrue($this->decision($days)->canViewData, "day side at {$moment}");
            self::assertTrue($this->decision($nights)->canViewData, "night side at {$moment}");
        }
    }

    public function test_a_roster_posted_early_grants_nothing_until_0800_and_the_outgoing_member_keeps_access_until_then(): void
    {
        $today = $this->officer('Firefighter', 'Today Rider');
        $tomorrow = $this->officer('Firefighter', 'Tomorrow Rider');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($today)], self::CHIEF => [$this->row($today)]]);
        DailyRosterSnapshotFactory::publish(self::TOMORROW, [self::C5 => [$this->row($tomorrow)], self::CHIEF => [$this->row($tomorrow)]]);

        $this->travelToLocal('2026-10-21 07:59');
        self::assertTrue($this->decision($today)->canViewData);
        self::assertTrue($this->allows300($today));
        self::assertFalse($this->decision($tomorrow)->canViewData);
        self::assertFalse($this->allows300($tomorrow));

        $this->travelToLocal('2026-10-21 08:00');
        self::assertFalse($this->decision($today)->canViewData);
        self::assertFalse($this->allows300($today));
        self::assertTrue($this->decision($tomorrow)->canViewData);
        self::assertTrue($this->allows300($tomorrow));
    }

    public function test_with_no_roster_at_0800_yesterdays_rider_is_out_and_rank_and_seat_are_untouched(): void
    {
        $rider = $this->officer('Firefighter');
        $chief = $this->officer('Division Chief');
        $holder = $this->officer('Firefighter');
        $this->seat($holder, 'Captain 5', 'B Shift');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($rider)]]);

        $this->travelToLocal('2026-10-21 08:01');

        self::assertFalse($this->decision($rider)->canViewData);
        self::assertTrue($this->allows300($chief));
        self::assertTrue($this->decision($holder)->canViewData);
    }

    public function test_being_removed_from_the_seat_ends_access_with_the_next_export(): void
    {
        $first = $this->officer('Firefighter');
        $second = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($first)]], ['generated_at' => $this->at('2026-10-20 09:00')]);
        self::assertTrue($this->decision($first)->canViewData);

        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($second)]], ['generated_at' => $this->at('2026-10-20 10:00')]);

        self::assertFalse($this->decision($first)->canViewData);
        self::assertTrue($this->decision($second)->canViewData);
    }

    public function test_only_a_working_row_counts(): void
    {
        $off = $this->officer('Firefighter');
        $annotation = $this->officer('Firefighter');
        $unknown = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [
            $this->row($off, ['code' => 'ADONIS DAY', 'availability' => 'not_working', 'category' => 'A-Day']),
            $this->row($annotation, ['code' => '^OOC', 'availability' => 'annotation', 'category' => 'Incentive']),
            $this->row($unknown, ['code' => 'GWA', 'availability' => 'unknown', 'category' => 'Other']),
        ]]);

        foreach ([$off, $annotation, $unknown] as $user) {
            self::assertFalse($this->decision($user)->canViewData);
        }
    }

    public function test_another_section_never_counts(): void
    {
        $engine = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [$this->row($engine)]]);

        self::assertFalse($this->decision($engine)->canViewData);
        self::assertFalse($this->allows300($engine));
    }

    public function test_a_roster_for_the_wrong_rotation_shift_counts_for_nothing(): void
    {
        $rider = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($rider)]], ['shift' => 'C']);

        self::assertFalse($this->decision($rider)->canViewData);
    }

    public function test_an_administrators_deny_beats_the_roster(): void
    {
        $rider = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($rider)]]);
        app(Captain5AdministrationService::class)->setOverride($this->captain5Administrator(), (int) $rider->employee_profile_id, 'deny', 'Under review');

        self::assertFalse($this->decision($rider)->canViewData);
    }

    public function test_an_account_that_cannot_sign_in_gets_nothing(): void
    {
        $unfinished = $this->officer('Firefighter');
        $departed = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($unfinished), $this->row($departed, ['seat' => 1])]]);
        DB::table('users')->where('id', $unfinished->id)->update(['must_change_password' => true]);
        DB::table('employees')->where('id', $departed->employee_profile_id)->update(['roster_status' => 'departed']);

        self::assertFalse($this->decision($unfinished)->canViewData);
        self::assertFalse($this->decision($departed)->canViewData);
    }

    public function test_the_setting_turns_it_all_off(): void
    {
        $rider = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($rider)], self::CHIEF => [$this->row($rider)]]);
        config()->set('daily_roster.seat_access_enabled', false);

        self::assertFalse($this->decision($rider)->canViewData);
        self::assertFalse($this->allows300($rider));
    }

    public function test_nothing_is_stored_as_a_permission_and_nothing_extra_opens(): void
    {
        $rider = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($rider)], self::CHIEF => [$this->row($rider)]]);
        self::assertTrue($this->allows300($rider));

        self::assertSame(0, DB::table('model_has_permissions')->where('model_id', $rider->id)->count());
        self::assertFalse($this->decision($rider)->canConfigure);
        self::assertFalse($rider->fresh()->hasCurrentAdminPanelEntitlement());
    }

    public function test_every_chief_rank_reaches_the_300_page_but_a_lookalike_title_does_not(): void
    {
        self::assertSame(Command300Access::SOURCE_DEPUTY_FIRE_CHIEF, app(Command300Access::class)->source($this->officer('Deputy Fire Chief')));
        self::assertSame(Command300Access::SOURCE_FIRE_CHIEF, app(Command300Access::class)->source($this->officer('Fire Chief')));
        self::assertFalse($this->allows300($this->officer('Chief Plans Analyst #3')));
    }

    public function test_existing_access_is_kept(): void
    {
        self::assertTrue($this->allows300($this->officer('Captain')));
        self::assertTrue($this->decision($this->officer('Lieutenant'))->canViewData);
    }

    public function test_narcotics_review_is_limited_to_the_hours_on_the_seat(): void
    {
        $days = $this->officer('Lieutenant', 'Day Side');
        $nights = $this->officer('Lieutenant', 'Night Side');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [
            $this->row($days, ['from' => '08:00', 'through' => '20:00', 'hours' => 12.0]),
            $this->row($nights, ['from' => '20:00', 'through' => '08:00', 'hours' => 12.0]),
        ]]);
        $canReview = function (User $user): bool {
            $this->forgetCaptain5Memo();

            return app(NarcoticsCheckAccess::class)->canReview($user->fresh());
        };

        $this->travelToLocal('2026-10-20 19:59');
        self::assertTrue($canReview($days));
        self::assertFalse($canReview($nights));

        $this->travelToLocal('2026-10-20 20:00');
        self::assertFalse($canReview($days));
        self::assertTrue($canReview($nights));
        self::assertTrue($this->decision($days)->canViewData, 'The page stays open for the whole duty day');
    }

    public function test_a_bid_holder_on_an_a_day_keeps_review_but_is_no_longer_the_shift_captain_5(): void
    {
        $holder = $this->officer('Captain', 'Seat Captain');
        $this->seat($holder, 'Captain 5', 'A Shift');
        $rider = $this->officer('Captain', 'Overtime Captain');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [
            $this->row($holder, ['code' => 'ADONIS DAY', 'availability' => 'not_working', 'category' => 'A-Day']),
            $this->row($rider, ['seat' => 0]),
        ]]);

        self::assertFalse($this->decision($holder)->isShownCaptain5);
        self::assertTrue($this->decision($rider)->isShownCaptain5);
        self::assertSame('shift_captain5', $this->decision($rider)->authority());
        $this->forgetCaptain5Memo();
        self::assertTrue(app(NarcoticsCheckAccess::class)->canReview($holder->fresh()));
    }

    public function test_the_explanation_names_the_roster(): void
    {
        $rider = $this->officer('Firefighter');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::CHIEF => [$this->row($rider)], self::C5 => [$this->row($rider)]]);
        $this->forgetCaptain5Memo();

        self::assertSame("Automatic — On today's TeleStaff roster", app(Command300Access::class)->sourceLabel(Command300Access::SOURCE_ROSTER));
        self::assertContains("On today's TeleStaff roster in the Captain 5 seat", app(Captain5AccessExplainer::class)->explain($rider->fresh())['sources']);
    }

    public function test_seat_changes_are_logged_once_without_any_employee_id_or_name(): void
    {
        $rider = $this->officer('Firefighter', 'Secret Name');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($rider)]]);

        Artisan::call('daily-roster:reconcile');
        Artisan::call('daily-roster:reconcile');

        $event = RosterEvent::query()->where('action', RosterStateRecorder::ACTION_SEAT_HOLDERS_CHANGED)->sole();
        self::assertSame('captain_5', $event->reason);
        self::assertSame([(int) $rider->id], $event->context['user_ids']);
        $stored = (string) json_encode($event->toArray());
        self::assertStringNotContainsString((string) $rider->employee_id, $stored);
        self::assertStringNotContainsString('Secret Name', $stored);
    }

    public function test_administrators_are_told_once_when_the_person_in_a_seat_has_not_activated(): void
    {
        $admin = $this->superAdmin();
        $ready = $this->officer('Firefighter', 'Ready Member');
        $unfinished = $this->officer('Firefighter', 'Unfinished Member');
        DB::table('users')->where('id', $unfinished->id)->update(['must_change_password' => true]);
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::C5 => [$this->row($ready), $this->row($unfinished, ['seat' => 1])]]);

        Artisan::call('daily-roster:reconcile');
        Artisan::call('daily-roster:reconcile');

        $alerts = Notification::sent($admin, DatabaseNotification::class)
            ->map(static fn (DatabaseNotification $alert): string => (string) json_encode($alert->data))
            ->filter(static fn (string $alert): bool => str_contains($alert, 'has not finished setting up'));
        self::assertCount(1, $alerts);
        self::assertStringContainsString('Unfinished Member', (string) $alerts->first());
        self::assertStringContainsString('Captain 5', (string) $alerts->first());
    }

    public function test_administrators_are_told_when_someone_gets_a_page_from_the_roster_alone(): void
    {
        $admin = $this->superAdmin();
        $firefighter = $this->officer('Firefighter', 'Unusual Rider');
        $lieutenant = $this->officer('Lieutenant', 'Usual Officer');
        $captain = $this->officer('Captain', 'Usual Captain');
        DailyRosterSnapshotFactory::publish(self::TODAY, [
            self::C5 => [$this->row($firefighter), $this->row($lieutenant, ['seat' => 1])],
            self::CHIEF => [$this->row($firefighter), $this->row($captain, ['seat' => 1])],
        ]);

        Artisan::call('daily-roster:reconcile');
        Artisan::call('daily-roster:reconcile');

        $alerts = Notification::sent($admin, DatabaseNotification::class)
            ->map(static fn (DatabaseNotification $alert): string => (string) json_encode($alert->data))
            ->filter(static fn (string $alert): bool => str_contains($alert, 'only because the TeleStaff roster'))
            ->values();
        self::assertCount(2, $alerts);
        self::assertStringContainsString('Unusual Rider', (string) $alerts[0]);
        self::assertStringContainsString('Unusual Rider', (string) $alerts[1]);
    }

    public function test_the_next_shifts_rider_is_flagged_before_the_shift_starts(): void
    {
        $admin = $this->superAdmin();
        $firefighter = $this->officer('Firefighter', 'Early Warning');
        DailyRosterSnapshotFactory::publish(self::TOMORROW, [self::C5 => [$this->row($firefighter)]]);

        Artisan::call('daily-roster:reconcile');

        $alerts = Notification::sent($admin, DatabaseNotification::class)
            ->map(static fn (DatabaseNotification $alert): string => (string) json_encode($alert->data))
            ->filter(static fn (string $alert): bool => str_contains($alert, 'Early Warning'));
        self::assertCount(1, $alerts);
        self::assertFalse($this->decision($firefighter)->canViewData, 'Still no access until 08:00');
    }

    public function test_a_broken_roster_read_closes_this_route_and_leaves_rank_alone(): void
    {
        $rider = $this->officer('Firefighter');
        $captain = $this->officer('Captain');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::CHIEF => [$this->row($rider)]]);
        Schema::drop('roster_assignments');

        self::assertFalse($this->allows300($rider));
        self::assertTrue($this->allows300($captain));
    }
}
