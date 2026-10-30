<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\DailyRoster\OnDutyOfficerDisplay;
use App\Services\DailyRoster\OnDutyOfficerDisplayResolver;
use App\Services\Security\Command300Access;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * Master directive section 8: who the cards show at each instant. Display only; the authority side
 * of the same scenarios is proved in tests/Feature/DailyRoster/OnDutyAuthorityBoundaryTest.php.
 * 2026-10-20 is an A shift duty day; every name here is synthetic.
 */
final class OnDutyOfficerDisplayResolverTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const DUTY_DATE = '2026-10-20';

    private const CAPTAIN_5_SECTION = 'Rescue Division / Captain 5';

    private const COMMAND_300_SECTION = 'Suppression / Division Chief 300';

    private User $bidCaptain;

    private User $rosterCaptain;

    private User $rosterRelief;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:30:00');
        config()->set('daily_roster.card_display_enabled', true);
        $this->bidCaptain = $this->officer('Captain', 'Bid Seat Captain');
        $this->seat($this->bidCaptain, 'Captain 5', 'A Shift');
        $this->rosterCaptain = $this->officer('Lieutenant', 'Roster Officer');
        $this->rosterRelief = $this->officer('Captain', 'Relief Officer');
    }

    private function resolve(string $local = '2026-10-20 10:30', string $role = Captain5ActingRequest::ROLE_CAPTAIN_5): OnDutyOfficerDisplay
    {
        return app(OnDutyOfficerDisplayResolver::class)->resolve($role, CarbonImmutable::parse($local, 'America/New_York'));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function publishCaptain5(array $rows, array $options = [], string $dutyDate = self::DUTY_DATE): void
    {
        DailyRosterSnapshotFactory::publish($dutyDate, [self::CAPTAIN_5_SECTION => $rows], $options);
        $this->forgetCaptain5Memo();
    }

    /** @return array<string, mixed> */
    private function working(User $user, array $overrides = []): array
    {
        return array_merge(['employee_id' => $user->employee_id, 'name' => 'SOURCE, PRINTED', 'rank' => 'Capt', 'code' => 'SP'], $overrides);
    }

    private function assertShowsBidSeat(OnDutyOfficerDisplay $shown, string $freshness, ?string $reason = null): void
    {
        $payload = $shown->toArray();
        self::assertSame(OnDutyCaptain5::SOURCE_BID, $payload['source']);
        self::assertSame('Bid Seat Captain', $payload['name']);
        self::assertSame('Captain', $payload['rank']);
        self::assertSame($freshness, $payload['freshness']);
        self::assertSame($reason, $payload['reason']);
        self::assertNull($payload['identity']);
    }

    private function assertUnknown(OnDutyOfficerDisplay $shown, string $reason): void
    {
        $payload = $shown->toArray();
        self::assertSame(OnDutyCaptain5::SOURCE_UNKNOWN, $payload['source']);
        self::assertFalse($payload['resolved']);
        self::assertNull($payload['name'], 'Nobody is shown, never the bid name');
        self::assertNull($payload['rank']);
        self::assertSame($reason, $payload['reason']);
        self::assertSame(OnDutyOfficerDisplay::FRESHNESS_CURRENT, $payload['freshness']);
    }

    // ------------------------------------------------------------- flag, contract and shape

    public function test_with_the_flag_off_the_payload_is_exactly_the_seat_result_and_the_roster_is_not_read(): void
    {
        config()->set('daily_roster.card_display_enabled', false);
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        Schema::drop('roster_assignments');

        $payload = $this->resolve()->toArray();

        self::assertSame(app(Captain5DutyRoster::class)->onDuty()->toArray(), $payload);
        self::assertSame(['duty_date', 'shift', 'source', 'resolved', 'name', 'rank', 'acting_until', 'acting_request_id'], array_keys($payload));
    }

    public function test_the_flag_defaults_to_off(): void
    {
        $config = require base_path('config/daily_roster.php');

        self::assertFalse($config['card_display_enabled']);
    }

    public function test_a_unique_working_person_is_shown_with_the_hub_name_and_rank_and_no_employee_id(): void
    {
        $this->rosterCaptain->employeeProfile->forceFill(['display_name' => 'Preferred Name'])->save();
        $this->publishCaptain5([$this->working($this->rosterCaptain)], ['generated_at' => '2026-10-20 13:14:15']);

        $payload = $this->resolve()->toArray();

        self::assertSame(OnDutyOfficerDisplay::SOURCE_TELESTAFF, $payload['source']);
        self::assertTrue($payload['resolved']);
        self::assertSame('Preferred Name', $payload['name']);
        self::assertSame('Lieutenant', $payload['rank'], 'Rank comes from the Hub, never the roster');
        self::assertSame('2026-10-20T13:14:15+00:00', $payload['as_of']);
        self::assertSame(OnDutyOfficerDisplay::FRESHNESS_CURRENT, $payload['freshness']);
        self::assertSame(OnDutyOfficerDisplay::IDENTITY_LINKED, $payload['identity']);
        self::assertNull($payload['reason']);
        self::assertNull($payload['acting_until']);
        self::assertNull($payload['acting_request_id']);
        self::assertSame('A', $payload['shift']);
        self::assertSame(self::DUTY_DATE, $payload['duty_date']);
        self::assertStringNotContainsString((string) $this->rosterCaptain->employee_id, (string) json_encode($payload));
        self::assertSame(
            ['duty_date', 'shift', 'source', 'resolved', 'name', 'rank', 'acting_until', 'acting_request_id', 'as_of', 'freshness', 'identity', 'reason'],
            array_keys($payload),
        );
    }

    public function test_each_role_reads_only_its_own_section(): void
    {
        $chief = $this->officer('Division Chief', 'Bid Chief');
        $this->seat($chief, Command300Access::COMMAND_UNIT_LABEL, 'A Shift');
        $rosterChief = $this->officer('Division Chief', 'Roster Chief');
        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [
            self::COMMAND_300_SECTION => [$this->working($rosterChief, ['rank' => 'DC'])],
            self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)],
        ]);
        $this->forgetCaptain5Memo();

        self::assertSame('Roster Chief', $this->resolve(role: Captain5ActingRequest::ROLE_COMMAND_300)->name);
        self::assertSame('Division Chief', $this->resolve(role: Captain5ActingRequest::ROLE_COMMAND_300)->rank);
        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(OnDutyOfficerDisplayResolver::class)->resolve('engine_1');
    }

    public function test_the_resolver_is_scoped_to_the_request(): void
    {
        $first = app(OnDutyOfficerDisplayResolver::class);
        self::assertSame($first, app(OnDutyOfficerDisplayResolver::class));
        app()->forgetScopedInstances();
        self::assertNotSame($first, app(OnDutyOfficerDisplayResolver::class));
    }

    public function test_the_display_query_count_stays_small(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        // The seat answer is already memoized by the access decision on a real request.
        app(Captain5DutyRoster::class)->onDuty(CarbonImmutable::parse('2026-10-20 10:30', 'America/New_York')->utc());

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $this->resolve();

        self::assertLessThanOrEqual(8, $queries);
    }

    // ------------------------------------------------------------- the 08:00 boundary

    public function test_at_0800_before_any_export_the_card_shows_todays_seat_and_today_shift_never_yesterdays_roster(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-19', [self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)]]);
        $this->forgetCaptain5Memo();

        $shown = $this->resolve('2026-10-20 08:00');

        $this->assertShowsBidSeat($shown, OnDutyOfficerDisplay::FRESHNESS_AWAITING);
        self::assertSame('A', $shown->toArray()['shift']);
        self::assertSame(self::DUTY_DATE, $shown->toArray()['duty_date']);
        self::assertNull($shown->toArray()['as_of']);
    }

    public function test_at_0759_the_previous_duty_days_snapshot_still_applies(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-19', [self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)]]);
        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [self::CAPTAIN_5_SECTION => [$this->working($this->rosterRelief)]]);
        $this->forgetCaptain5Memo();

        $before = $this->resolve('2026-10-20 07:59')->toArray();
        self::assertSame(OnDutyOfficerDisplay::SOURCE_TELESTAFF, $before['source']);
        self::assertSame('Roster Officer', $before['name']);
        self::assertSame('C', $before['shift'], '2026-10-19 is the shift before A in the rotation');

        $after = $this->resolve('2026-10-20 08:00')->toArray();
        self::assertSame('Relief Officer', $after['name']);
        self::assertSame('A', $after['shift']);
    }

    // ------------------------------------------------------------- no email, late, stale

    public function test_no_email_reads_awaiting_before_the_cutoff_and_delayed_after_it(): void
    {
        // The freshness resolver says "none" until any roster has ever been published.
        $this->assertShowsBidSeat($this->resolve('2026-10-20 09:00'), OnDutyOfficerDisplay::FRESHNESS_AWAITING);
        $this->assertShowsBidSeat($this->resolve('2026-10-20 11:00'), OnDutyOfficerDisplay::FRESHNESS_AWAITING);

        DailyRosterSnapshotFactory::publish('2026-10-18', [self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)]]);
        $this->forgetCaptain5Memo();
        $this->assertShowsBidSeat($this->resolve('2026-10-20 09:59'), OnDutyOfficerDisplay::FRESHNESS_AWAITING);
        $this->assertShowsBidSeat($this->resolve('2026-10-20 10:00'), OnDutyOfficerDisplay::FRESHNESS_DELAYED);
    }

    public function test_a_held_export_with_nothing_published_reads_delayed_and_shows_the_seat(): void
    {
        RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(), 'state' => RosterImport::STATE_NEEDS_REVIEW, 'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', 'held'), 'sender' => 'sender@example.test', 'received_at' => now(), 'duty_date' => self::DUTY_DATE,
        ]);

        $this->assertShowsBidSeat($this->resolve(), OnDutyOfficerDisplay::FRESHNESS_DELAYED);
    }

    public function test_a_held_later_export_does_not_hide_the_good_one_already_showing(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(), 'state' => RosterImport::STATE_NEEDS_REVIEW, 'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', 'held'), 'sender' => 'sender@example.test', 'received_at' => now(), 'duty_date' => self::DUTY_DATE,
        ]);

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_a_stale_snapshot_on_the_wrong_shift_is_ignored(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)], ['shift' => 'B']);

        $this->assertShowsBidSeat($this->resolve(), OnDutyOfficerDisplay::FRESHNESS_DELAYED);
    }

    public function test_a_pointer_at_another_days_snapshot_is_ignored(): void
    {
        $yesterday = DailyRosterSnapshotFactory::publish('2026-10-19', [self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)]]);
        RosterActiveVersion::query()->create(['duty_date' => self::DUTY_DATE, 'roster_snapshot_id' => $yesterday->id, 'last_confirmed_at' => now()]);
        $this->forgetCaptain5Memo();

        $this->assertShowsBidSeat($this->resolve(), OnDutyOfficerDisplay::FRESHNESS_DELAYED);
    }

    public function test_a_published_snapshot_without_the_active_pointer_is_ignored(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)], ['activate' => false]);

        $this->assertShowsBidSeat($this->resolve('2026-10-20 09:00'), OnDutyOfficerDisplay::FRESHNESS_AWAITING);
    }

    public function test_with_the_rotation_disabled_nothing_is_trusted_and_nobody_is_guessed(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        config()->set('captain5.shift_rotation.enabled', false);
        $this->forgetCaptain5Memo();

        $payload = $this->resolve()->toArray();

        self::assertSame(OnDutyCaptain5::SOURCE_UNKNOWN, $payload['source']);
        self::assertNull($payload['name']);
        self::assertNull($payload['shift']);
    }

    // ------------------------------------------------------------- who the section names

    public function test_no_officer_only_a_vacancy_is_no_working_row_not_the_bid_seat(): void
    {
        $this->publishCaptain5([['kind' => RosterAssignment::KIND_VACANCY_OPEN, 'name' => '?,?', 'code' => null, 'rank' => 'Capt']]);

        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NO_WORKING_ROW);
    }

    public function test_a_section_missing_on_a_valid_snapshot_shows_the_seat_with_a_reason(): void
    {
        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, ['Station 1 / Engine 1' => [$this->working($this->rosterCaptain, ['rank' => 'Lt'])]]);
        $this->forgetCaptain5Memo();

        $shown = $this->resolve();

        $this->assertShowsBidSeat($shown, OnDutyOfficerDisplay::FRESHNESS_CURRENT, OnDutyOfficerDisplay::REASON_SECTION_MISSING);
        self::assertNotNull($shown->toArray()['as_of']);
    }

    public function test_two_different_people_working_at_the_same_instant_is_a_conflict(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain), $this->working($this->rosterRelief, ['seat' => 1])]);

        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_CONFLICT);
    }

    public function test_the_same_person_on_two_working_rows_is_unique(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain), $this->working($this->rosterCaptain, ['code' => '+OT', 'seat' => 1])]);

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_the_real_shape_absence_first_then_overtime_continuations_for_someone_else(): void
    {
        $this->publishCaptain5([
            ['employee_id' => $this->bidCaptain->employee_id, 'name' => 'SEAT, HOLDER', 'rank' => 'Capt', 'code' => 'ADMS', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'category' => 'Leave'],
            $this->working($this->rosterCaptain, ['code' => '+OT', 'category' => 'Overtime', 'from' => '08:00', 'through' => '20:00', 'hours' => 12.0, 'seat' => 0, 'continuation' => true]),
            $this->working($this->rosterCaptain, ['code' => '+OT', 'category' => 'Overtime', 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0, 'seat' => 0, 'continuation' => true]),
        ]);

        self::assertSame('Roster Officer', $this->resolve('2026-10-20 12:00')->name);
        self::assertSame('Roster Officer', $this->resolve('2026-10-20 21:00')->name);
    }

    public function test_a_morning_and_overnight_split_between_two_people_changes_at_the_boundary(): void
    {
        $this->publishCaptain5([
            $this->working($this->rosterCaptain, ['from' => '08:00', 'through' => '20:00', 'hours' => 12.0]),
            $this->working($this->rosterRelief, ['from' => '20:00', 'through' => '08:00', 'hours' => 12.0, 'seat' => 0]),
        ]);

        self::assertSame('Roster Officer', $this->resolve('2026-10-20 12:00')->name);
        self::assertSame('Roster Officer', $this->resolve('2026-10-20 19:59')->name);
        self::assertSame('Relief Officer', $this->resolve('2026-10-20 20:00')->name, 'Half-open: the first row has ended exactly at 20:00');
        self::assertSame('Relief Officer', $this->resolve('2026-10-20 21:00')->name);
        self::assertSame('Relief Officer', $this->resolve('2026-10-21 07:59')->name);
    }

    public function test_a_time_gap_is_no_working_row(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain, ['from' => '08:00', 'through' => '12:00', 'hours' => 4.0])]);

        $this->assertUnknown($this->resolve('2026-10-20 13:00'), OnDutyOfficerDisplay::REASON_NO_WORKING_ROW);
    }

    public function test_not_working_only_is_no_working_row(): void
    {
        $this->publishCaptain5([['employee_id' => $this->rosterCaptain->employee_id, 'code' => 'VACA', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'category' => 'Vacation', 'rank' => 'Capt']]);

        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NO_WORKING_ROW);
    }

    public function test_an_unknown_code_alone_needs_review_and_never_falls_back_to_the_bid_seat(): void
    {
        $this->publishCaptain5([['employee_id' => $this->rosterCaptain->employee_id, 'code' => 'GWA', 'availability' => RosterAssignment::AVAILABILITY_UNKNOWN, 'category' => 'Other', 'rank' => 'Capt']]);

        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
    }

    public function test_a_working_row_with_an_unknown_code_for_someone_else_fails_closed(): void
    {
        $this->publishCaptain5([
            $this->working($this->rosterCaptain),
            ['employee_id' => $this->rosterRelief->employee_id, 'code' => 'TRNADM', 'availability' => RosterAssignment::AVAILABILITY_UNKNOWN, 'category' => 'Other', 'rank' => 'Capt', 'seat' => 1],
        ]);

        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
    }

    public function test_a_working_row_with_an_unknown_code_for_the_same_person_shows_them(): void
    {
        $this->publishCaptain5([
            $this->working($this->rosterCaptain),
            ['employee_id' => $this->rosterCaptain->employee_id, 'code' => '.PMS', 'availability' => RosterAssignment::AVAILABILITY_UNKNOWN, 'category' => 'Other', 'rank' => 'Capt', 'seat' => 1],
        ]);

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_an_annotation_is_never_a_candidate(): void
    {
        $this->publishCaptain5([['employee_id' => $this->rosterCaptain->employee_id, 'code' => '^OOC', 'availability' => RosterAssignment::AVAILABILITY_ANNOTATION, 'category' => 'Incentive', 'rank' => 'Capt']]);
        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NO_WORKING_ROW);
    }

    public function test_an_annotation_beside_a_working_row_changes_nothing(): void
    {
        $this->publishCaptain5([
            $this->working($this->rosterCaptain),
            ['employee_id' => $this->rosterRelief->employee_id, 'code' => '^ROC', 'availability' => RosterAssignment::AVAILABILITY_ANNOTATION, 'category' => 'Incentive', 'rank' => 'Capt', 'seat' => 1],
        ]);

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_a_vacancy_beside_a_working_row_is_ignored(): void
    {
        $this->publishCaptain5([
            ['kind' => RosterAssignment::KIND_VACANCY_SUPPRESSED, 'name' => '-,-', 'code' => null, 'rank' => 'Capt'],
            $this->working($this->rosterCaptain, ['seat' => 1]),
        ]);

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_a_working_row_with_no_employee_id_needs_review(): void
    {
        $this->publishCaptain5([['employee_id' => null, 'name' => 'NO, IDENTIFIER', 'rank' => 'Capt', 'code' => 'SP']]);

        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
    }

    // ------------------------------------------------------------- exchange, overtime, leave elsewhere

    /** @return array<string, array{string}> */
    public static function positiveCodes(): array
    {
        return ['overtime printed +OT' => ['+OT'], 'overtime' => ['OT'], 'exchange on' => ['2WXON'], 'exchange engine on' => ['2WXEON']];
    }

    /** @dataProvider positiveCodes */
    public function test_exchange_on_and_overtime_outrank_an_a_day_or_day_off_elsewhere(string $code): void
    {
        foreach (['A-Day' => 'ADONIS DAY', 'Day off' => 'DAYOFF'] as $category => $absence) {
            DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [
                self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain, ['code' => $code])],
                'Station 2 / Engine 2' => [['employee_id' => $this->rosterCaptain->employee_id, 'code' => $absence, 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'category' => $category, 'rank' => 'Lt']],
            ]);
            $this->forgetCaptain5Memo();

            self::assertSame('Roster Officer', $this->resolve()->name, $category);
        }
    }

    public function test_an_a_day_elsewhere_with_only_a_scheduled_row_needs_review(): void
    {
        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [
            self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)],
            'Station 2 / Engine 2' => [['employee_id' => $this->rosterCaptain->employee_id, 'code' => 'ADONIS DAY', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'category' => 'A-Day', 'rank' => 'Lt']],
        ]);
        $this->forgetCaptain5Memo();

        $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
    }

    public function test_leave_elsewhere_needs_review_even_with_overtime_in_the_seat(): void
    {
        foreach (['VACA' => 'Vacation', 'SIC' => 'Sick', 'ADMS' => 'Leave'] as $code => $category) {
            DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [
                self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain, ['code' => '+OT'])],
                'Station 2 / Engine 2' => [['employee_id' => $this->rosterCaptain->employee_id, 'code' => $code, 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'category' => $category, 'rank' => 'Lt']],
            ]);
            $this->forgetCaptain5Memo();

            $this->assertUnknown($this->resolve(), OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
        }
    }

    public function test_leave_elsewhere_at_a_different_time_of_day_does_not_count(): void
    {
        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [
            self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)],
            'Station 2 / Engine 2' => [['employee_id' => $this->rosterCaptain->employee_id, 'code' => 'VACA', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'category' => 'Vacation', 'rank' => 'Lt', 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0]],
        ]);
        $this->forgetCaptain5Memo();

        self::assertSame('Roster Officer', $this->resolve('2026-10-20 12:00')->name);
        $this->assertUnknown($this->resolve('2026-10-20 21:00'), OnDutyOfficerDisplay::REASON_NEEDS_REVIEW);
    }

    public function test_someone_elses_leave_elsewhere_does_not_count(): void
    {
        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [
            self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)],
            'Station 2 / Engine 2' => [['employee_id' => $this->rosterRelief->employee_id, 'code' => 'VACA', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING, 'category' => 'Vacation', 'rank' => 'Lt']],
        ]);
        $this->forgetCaptain5Memo();

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    // ------------------------------------------------------------- identity

    public function test_an_id_no_hub_member_holds_shows_the_source_name_and_seat_rank_as_unlinked(): void
    {
        $this->publishCaptain5([['employee_id' => '99999', 'name' => 'OUTSIDE, MEMBER', 'rank' => 'Capt 5', 'code' => 'SP']]);

        $payload = $this->resolve()->toArray();

        self::assertSame(OnDutyOfficerDisplay::SOURCE_TELESTAFF, $payload['source']);
        self::assertSame('OUTSIDE, MEMBER', $payload['name']);
        self::assertSame('Capt 5', $payload['rank']);
        self::assertSame(OnDutyOfficerDisplay::IDENTITY_UNLINKED, $payload['identity']);
        self::assertNotSame('Bid Seat Captain', $payload['name']);
    }

    public function test_a_departed_member_is_never_linked(): void
    {
        $this->rosterCaptain->employeeProfile->forceFill(['roster_status' => 'departed'])->save();
        $this->publishCaptain5([$this->working($this->rosterCaptain, ['name' => 'FORMER, MEMBER'])]);

        $payload = $this->resolve()->toArray();

        self::assertSame('FORMER, MEMBER', $payload['name']);
        self::assertSame(OnDutyOfficerDisplay::IDENTITY_UNLINKED, $payload['identity']);
    }

    public function test_identity_is_an_exact_string_match(): void
    {
        $this->rosterCaptain->employeeProfile->forceFill(['employee_id' => '00123'])->save();
        $this->publishCaptain5([['employee_id' => '123', 'name' => 'PADDED, NOT', 'rank' => 'Capt', 'code' => 'SP']]);

        $payload = $this->resolve()->toArray();

        self::assertSame(OnDutyOfficerDisplay::IDENTITY_UNLINKED, $payload['identity']);
        self::assertSame('PADDED, NOT', $payload['name']);
    }

    // ------------------------------------------------------------- acting

    public function test_an_approved_acting_member_beats_the_roster_and_the_payload_carries_no_roster_label(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        $acting = $this->actingAssignment($this->rosterRelief, $this->captain5Administrator());

        $payload = $this->resolve()->toArray();

        self::assertSame(OnDutyCaptain5::SOURCE_ACTING, $payload['source']);
        self::assertSame('Relief Officer', $payload['name']);
        self::assertSame($acting->getKey(), $payload['acting_request_id']);
        self::assertNotNull($payload['acting_until']);
        self::assertNull($payload['as_of']);
        self::assertSame(OnDutyOfficerDisplay::FRESHNESS_NOT_APPLICABLE, $payload['freshness']);
        self::assertNull($payload['identity']);
        self::assertNull($payload['reason']);
    }

    public function test_when_an_acting_display_ends_the_card_returns_to_the_roster(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        $this->actingAssignment($this->rosterRelief, $this->captain5Administrator(), CarbonImmutable::parse('2026-10-20 12:00', 'America/New_York')->utc());

        self::assertSame('Relief Officer', $this->resolve('2026-10-20 11:59')->name);
        self::assertSame('Roster Officer', $this->resolve('2026-10-20 12:00')->name);
    }

    // ------------------------------------------------------------- corrections

    public function test_a_late_correction_replaces_the_answer_when_the_pointer_moves(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)], ['generated_at' => '2026-10-20 13:14:15']);
        self::assertSame('Roster Officer', $this->resolve()->name);

        $this->publishCaptain5([$this->working($this->rosterRelief)], ['generated_at' => '2026-10-20 14:30:00']);

        $payload = $this->resolve()->toArray();
        self::assertSame('Relief Officer', $payload['name']);
        self::assertSame('2026-10-20T14:30:00+00:00', $payload['as_of']);
    }

    public function test_a_replaced_import_that_is_not_pointed_at_is_never_read(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)], ['generated_at' => '2026-10-20 13:14:15']);
        $this->publishCaptain5([$this->working($this->rosterRelief)], ['generated_at' => '2026-10-20 12:30:00', 'activate' => false]);

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_a_rollback_to_an_earlier_snapshot_is_followed(): void
    {
        $first = DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [self::CAPTAIN_5_SECTION => [$this->working($this->rosterCaptain)]]);
        $this->publishCaptain5([$this->working($this->rosterRelief)]);
        self::assertSame('Relief Officer', $this->resolve()->name);

        RosterActiveVersion::query()->whereDate('duty_date', self::DUTY_DATE)->update(['roster_snapshot_id' => $first->id]);

        self::assertSame('Roster Officer', $this->resolve()->name);
    }

    public function test_a_replaced_import_whose_section_disappeared_does_not_keep_the_old_name(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, ['Station 1 / Engine 1' => [$this->working($this->rosterRelief, ['rank' => 'Lt'])]]);
        $this->forgetCaptain5Memo();

        $this->assertShowsBidSeat($this->resolve(), OnDutyOfficerDisplay::FRESHNESS_CURRENT, OnDutyOfficerDisplay::REASON_SECTION_MISSING);
    }

    // ------------------------------------------------------------- DST

    public function test_the_25_hour_duty_day_keeps_the_same_snapshot_through_the_repeated_hour(): void
    {
        // Clocks go back at 02:00 on 2026-11-01, inside the 2026-10-31 duty day.
        $calendar = app(DutyCalendar::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00', 'America/New_York'));
        DailyRosterSnapshotFactory::publish('2026-10-31', [self::CAPTAIN_5_SECTION => [
            $this->working($this->rosterCaptain, ['from' => '08:00', 'through' => '20:00', 'hours' => 12.0]),
            $this->working($this->rosterRelief, ['from' => '20:00', 'through' => '08:00', 'hours' => 13.0, 'seat' => 0]),
        ]]);
        $this->forgetCaptain5Memo();

        [$start, $end] = $calendar->window('2026-10-31');
        self::assertSame(25.0, $start->diffInHours($end));

        $name = fn (CarbonImmutable $at): ?string => app(OnDutyOfficerDisplayResolver::class)->resolve(Captain5ActingRequest::ROLE_CAPTAIN_5, $at)->name;

        self::assertSame('Roster Officer', $name(CarbonImmutable::parse('2026-10-31 12:00', 'America/New_York')));
        self::assertSame('Relief Officer', $name(CarbonImmutable::parse('2026-10-31 21:00', 'America/New_York')));
        // 01:30 happens twice; both are the same duty day and the same overnight row.
        self::assertSame('Relief Officer', $name(CarbonImmutable::parse('2026-11-01 05:30:00', 'UTC')), '01:30 EDT');
        self::assertSame('Relief Officer', $name(CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC')), '01:30 EST');
        self::assertSame('Relief Officer', $name(CarbonImmutable::parse('2026-11-01 07:59:00', 'America/New_York')));

        // 08:00 EST on 11-01 is the next duty day, with no roster yet.
        $next = app(OnDutyOfficerDisplayResolver::class)->resolve(Captain5ActingRequest::ROLE_CAPTAIN_5, CarbonImmutable::parse('2026-11-01 08:00:00', 'America/New_York'))->toArray();
        self::assertSame('2026-11-01', $next['duty_date']);
        self::assertNotSame(OnDutyOfficerDisplay::SOURCE_TELESTAFF, $next['source']);
    }

    // ------------------------------------------------------------- failure

    public function test_a_roster_read_failure_is_reported_and_the_page_still_gets_the_seat(): void
    {
        $this->publishCaptain5([$this->working($this->rosterCaptain)]);
        $seat = app(Captain5DutyRoster::class)->onDuty(CarbonImmutable::parse('2026-10-20 10:30', 'America/New_York')->utc())->toArray();
        Schema::drop('roster_assignments');
        Exceptions::fake();

        self::assertSame($seat, $this->resolve()->toArray());
        Exceptions::assertReported(QueryException::class);
    }
}
