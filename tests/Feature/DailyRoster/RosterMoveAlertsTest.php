<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Enums\AccountStatus;
use App\Jobs\DailyRoster\DetectRosterMoves;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterMoveBaseline;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\Employee;
use App\Models\NotificationDispatchRetry;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\CriticalAlertNotification;
use App\Notifications\RosterMoveNotification;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\RosterMoveDetector;
use App\Services\DailyRoster\RosterPublicationVerifier;
use App\Services\DailyRoster\RosterStateRecorder;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Role;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** Only a member on duty, only inside the 08:00 to 08:00 shift, and never as an email. */
final class RosterMoveAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-03';

    private const ENGINE = 'Station 1 / Engine 1';

    private const RESCUE = 'Station 3 / Rescue 3';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['daily_roster.mode' => 'publish']);
        $this->at('10:00');
    }

    public function test_a_member_moved_during_their_shift_is_told_where_they_now_ride(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::RESCUE => [$this->row('90001')]]);
        $this->assertSame(1, $this->detect());

        Notification::assertSentTo($member, RosterMoveNotification::class, function (RosterMoveNotification $alert) use ($member): bool {
            $database = $alert->toDatabase($member);
            $this->assertSame('Roster move: Rescue 3', $database['title']);
            $this->assertStringContainsString('Was: Engine 1', $database['body']);
            $this->assertStringContainsString('Now: Rescue 3', $database['body']);
            $this->assertSame('/daily-roster', $database['actions'][0]['url']);

            $push = $alert->toWebPush($member, $alert)->toArray();
            $this->assertSame('Roster move: Rescue 3', $push['title']);
            $this->assertSame('Open MBFD Hub for details.', $push['body']);

            return true;
        });
    }

    public function test_it_offers_only_push_and_in_app_and_never_email(): void
    {
        $category = app(NotificationCategoryRegistry::class)->get(NotificationCategoryRegistry::ROSTER_MOVES);

        $this->assertSame([NotificationCategory::CHANNEL_DATABASE, NotificationCategory::CHANNEL_WEBPUSH], $category->channels);
        $this->assertSame([], $category->locked);
        $this->assertTrue($category->defaults[NotificationCategory::CHANNEL_DATABASE]);
        $this->assertTrue($category->defaults[NotificationCategory::CHANNEL_WEBPUSH]);
        $this->assertFalse($category->defaults[NotificationCategory::CHANNEL_EMAIL]);

        $member = $this->member('90001');
        $member->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/device', 'public_key' => 'key', 'auth_token' => 'token']);
        $alert = new RosterMoveNotification('t', 'b', 'k');
        $this->assertSame(['database', WebPushChannel::class], $alert->via($member));
    }

    public function test_settings_show_it_to_a_rostered_member_and_not_to_an_account_with_no_personnel_link(): void
    {
        $registry = app(NotificationCategoryRegistry::class);

        $this->assertTrue($registry->forUser($this->member('90001'))->has(NotificationCategoryRegistry::ROSTER_MOVES));
        $this->assertFalse($registry->forUser(User::factory()->create())->has(NotificationCategoryRegistry::ROSTER_MOVES));
    }

    public function test_a_member_who_turned_both_channels_off_gets_nothing(): void
    {
        $member = $this->member('90001');
        UserNotificationSubscription::query()->create([
            'user_id' => $member->id,
            'event_key' => NotificationCategoryRegistry::ROSTER_MOVES,
            'database_enabled' => false,
            'webpush_enabled' => false,
            'email_enabled' => false,
        ]);
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::RESCUE => [$this->row('90001')]]);
        $this->detect();

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_member_can_keep_in_app_and_turn_push_off(): void
    {
        $member = $this->member('90001');
        UserNotificationSubscription::query()->create([
            'user_id' => $member->id,
            'event_key' => NotificationCategoryRegistry::ROSTER_MOVES,
            'database_enabled' => true,
            'webpush_enabled' => false,
            'email_enabled' => false,
        ]);

        $this->assertSame(['database'], (new RosterMoveNotification('t', 'b', 'k'))->via($member));
    }

    public function test_the_first_roster_of_the_shift_alerts_nobody(): void
    {
        $member = $this->member('90001');

        $this->publish([self::RESCUE => [$this->row('90001')]]);
        $this->assertSame(0, $this->detect());

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
        $this->assertNotNull($this->baselineSnapshotId());
    }

    public function test_a_change_to_tomorrows_roster_alerts_nobody(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        DailyRosterSnapshotFactory::publish('2026-11-04', [self::ENGINE => [$this->row('90001')]]);
        DailyRosterSnapshotFactory::publish('2026-11-04', [self::RESCUE => [$this->row('90001')]]);
        $this->detect();

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_change_made_before_0800_is_not_alerted_after_0800(): void
    {
        $member = $this->member('90001');
        $this->at('07:30');
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::ENGINE => [$this->row('90001')]]);
        $this->detect(); // Still yesterday's duty day: today's roster is not compared yet.
        DailyRosterSnapshotFactory::publish(self::TODAY, [self::RESCUE => [$this->row('90001')]]);

        $this->at('08:01');
        $this->detect();

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_member_who_has_not_started_yet_is_not_told_beforehand(): void
    {
        $member = $this->member('90001');
        $this->at('15:00');
        $this->baseline([self::ENGINE => [$this->row('90001', from: '20:00', through: '08:00')]]);

        $this->publish([self::RESCUE => [$this->row('90001', from: '20:00', through: '08:00')]]);
        $this->detect();

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_member_whose_hours_are_over_is_not_told(): void
    {
        $member = $this->member('90001');
        $this->at('21:00');
        $this->baseline([self::ENGINE => [$this->row('90001', through: '20:00')]]);

        $this->publish([self::RESCUE => [$this->row('90001', through: '20:00')]]);
        $this->detect();

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_move_later_in_the_shift_says_when_it_starts(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([
            self::ENGINE => [$this->row('90001', through: '18:00')],
            self::RESCUE => [$this->row('90001', from: '18:00', through: '08:00')],
        ]);
        $this->detect();

        Notification::assertSentTo($member, RosterMoveNotification::class, function (RosterMoveNotification $alert) use ($member): bool {
            $this->assertSame('Roster move: Rescue 3 from 18:00', $alert->toDatabase($member)['title']);

            return true;
        });
    }

    public function test_a_different_seat_on_the_same_unit_counts(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001', rank: 'Firefighter')]]);

        $this->publish([self::ENGINE => [$this->row('90001', rank: 'Firefighter DE')]]);
        $this->detect();

        Notification::assertSentTo($member, RosterMoveNotification::class, function (RosterMoveNotification $alert) use ($member): bool {
            $this->assertSame('Roster move: Engine 1', $alert->toDatabase($member)['title']);
            $this->assertStringContainsString('Now: Engine 1 as Firefighter DE', $alert->toDatabase($member)['body']);

            return true;
        });
    }

    public function test_being_marked_off_for_the_rest_of_the_shift_counts(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::ENGINE => [$this->row('90001', code: 'SIC', availability: RosterAssignment::AVAILABILITY_NOT_WORKING, category: 'Sick')]]);
        $this->detect();

        Notification::assertSentTo($member, RosterMoveNotification::class, function (RosterMoveNotification $alert) use ($member): bool {
            $this->assertSame('Roster change: not listed for the rest of this shift', $alert->toDatabase($member)['title']);

            return true;
        });
    }

    public function test_being_released_early_from_the_same_seat_counts(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::ENGINE => [$this->row('90001', through: '20:00')]]);
        $this->detect();

        Notification::assertSentTo($member, RosterMoveNotification::class, function (RosterMoveNotification $alert) use ($member): bool {
            $this->assertSame('Roster change: Engine 1 until 20:00', $alert->toDatabase($member)['title']);

            return true;
        });
    }

    public function test_a_status_code_certification_or_annotation_change_alerts_nobody(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::ENGINE => [
            ['employee_id' => '90001', 'name' => 'TESTER, ALPHA', 'code' => '+OT', 'category' => 'Overtime', 'certifications' => ['PM', 'DE']],
            ['employee_id' => '90001', 'name' => 'TESTER, ALPHA', 'code' => '^OOC', 'availability' => RosterAssignment::AVAILABILITY_ANNOTATION, 'category' => 'Incentive'],
        ]]);
        $this->assertSame(0, $this->detect());

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_back_to_back_rows_on_the_same_seat_are_not_a_move(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::ENGINE => [
            $this->row('90001', through: '14:00'),
            $this->row('90001', from: '14:00', through: '08:00', code: '+OT', category: 'Overtime'),
        ]]);
        $this->assertSame(0, $this->detect());

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_push_not_delivered_by_the_end_of_the_shift_is_dropped(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::RESCUE => [$this->row('90001')]]);
        $this->detect();

        Notification::assertSentTo($member, RosterMoveNotification::class, function (RosterMoveNotification $alert) use ($member): bool {
            $options = $alert->toWebPush($member, $alert)->getOptions();
            $this->assertSame('high', $options['urgency']);
            // 10:00 to 08:00 the next morning. The clocks do not change on this date.
            $this->assertSame(22 * 3600, $options['TTL']);

            return true;
        });
    }

    public function test_an_edit_to_hours_already_worked_alerts_nobody(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::ENGINE => [$this->row('90001', from: '09:00', through: '08:00')]]);
        $this->assertSame(0, $this->detect());

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_an_unrecognized_code_does_not_look_like_being_taken_off(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->publish([self::ENGINE => [$this->row('90001', code: 'HOOT', availability: RosterAssignment::AVAILABILITY_UNKNOWN, category: 'Other')]]);
        $this->assertSame(0, $this->detect());

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
    }

    public function test_only_the_member_who_moved_is_told(): void
    {
        $moved = $this->member('90001');
        $stayed = $this->member('90002');
        $this->baseline([self::ENGINE => [$this->row('90001'), $this->row('90002')]]);

        $this->publish([self::ENGINE => [$this->row('90002')], self::RESCUE => [$this->row('90001')]]);
        $this->assertSame(1, $this->detect());

        Notification::assertSentTo($moved, RosterMoveNotification::class);
        Notification::assertNotSentTo($stayed, RosterMoveNotification::class);
    }

    public function test_a_member_with_no_account_is_skipped_without_failing(): void
    {
        $member = $this->member('90002');
        $this->baseline([self::ENGINE => [$this->row('90001'), $this->row('90002')]]);

        $this->publish([self::RESCUE => [$this->row('90001'), $this->row('90002')]]);
        $this->detect();

        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_repeat_check_sends_nothing_more(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);
        $this->publish([self::RESCUE => [$this->row('90001')]]);

        $this->detect();
        $this->assertSame(0, $this->detect());

        Notification::assertSentToTimes($member, RosterMoveNotification::class, 1);
    }

    public function test_being_moved_back_and_forth_alerts_each_time(): void
    {
        $member = $this->member('90001');
        $first = $this->baseline([self::ENGINE => [$this->row('90001')]]);
        $second = $this->publish([self::RESCUE => [$this->row('90001')]]);
        $this->detect();

        $this->travel(5)->minutes();
        $this->point($first);
        $this->detect();

        $this->travel(5)->minutes();
        $this->point($second);
        $this->detect();

        Notification::assertSentToTimes($member, RosterMoveNotification::class, 3);
    }

    public function test_it_waits_until_the_roster_has_been_read_back(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);
        $snapshot = $this->publish([self::RESCUE => [$this->row('90001')]]);
        $import = RosterImport::query()->findOrFail($snapshot->roster_import_id);
        $import->forceFill(['state' => RosterImport::STATE_PUBLISHED, 'verified_at' => null])->save();

        $this->assertSame(0, $this->detect());
        Notification::assertNotSentTo($member, RosterMoveNotification::class);

        $import->forceFill(['state' => RosterImport::STATE_VERIFIED])->save();
        $this->assertSame(1, $this->detect());
        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    public function test_a_manual_transcription_on_either_side_alerts_nobody(): void
    {
        $member = $this->member('90001');
        $manual = $this->baseline([self::ENGINE => [$this->row('90001')]]);
        RosterImport::query()->whereKey($manual->roster_import_id)->update([
            'classification' => json_encode(['verdict' => 'match', 'reasons' => ['operator_attested', 'manual_transcription']]),
        ]);

        // TeleStaff's real export replacing the typed roster: differences may be typing slips.
        $this->publish([self::RESCUE => [$this->row('90001')]]);
        $this->assertSame(0, $this->detect());
        Notification::assertNotSentTo($member, RosterMoveNotification::class);

        // A real move after that does alert.
        $this->publish([self::ENGINE => [$this->row('90001')]]);
        $this->assertSame(1, $this->detect());
        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    public function test_too_many_changes_at_once_alert_administrators_instead_of_members(): void
    {
        config(['daily_roster.move_alerts.max_members' => 1]);
        $first = $this->member('90001');
        $second = $this->member('90002');
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $admin->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/admin', 'public_key' => 'key', 'auth_token' => 'token']);
        $this->baseline([self::ENGINE => [$this->row('90001'), $this->row('90002')]]);

        $this->publish([self::RESCUE => [$this->row('90001'), $this->row('90002')]]);
        $this->assertSame(0, $this->detect());

        Notification::assertNotSentTo($first, RosterMoveNotification::class);
        Notification::assertNotSentTo($second, RosterMoveNotification::class);
        Notification::assertSentTo($admin, CriticalAlertNotification::class);
    }

    public function test_the_administrator_alert_is_tried_again_if_it_did_not_go_out(): void
    {
        config(['daily_roster.move_alerts.max_members' => 1]);
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $admin->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/admin', 'public_key' => 'key', 'auth_token' => 'token']);
        $this->baseline([self::ENGINE => [$this->row('90001'), $this->row('90002')]]);
        $this->publish([self::RESCUE => [$this->row('90001'), $this->row('90002')]]);
        $this->detect();
        Notification::assertSentToTimes($admin, CriticalAlertNotification::class, 1);

        // Repeated checks do not repeat it.
        $this->detect();
        Notification::assertSentToTimes($admin, CriticalAlertNotification::class, 1);

        // A failed send clears its own marker (RosterAlerts), and then the next check sends it.
        Cache::forget('daily_roster_alert:move_alerts_held:'.self::TODAY);
        $this->detect();
        Notification::assertSentToTimes($admin, CriticalAlertNotification::class, 2);
    }

    public function test_an_alert_is_recorded_before_the_roster_baseline_commits_and_cleared_after(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);
        $this->publish([self::RESCUE => [$this->row('90001')]]);

        $this->detect();

        Notification::assertSentTo($member, RosterMoveNotification::class);
        $this->assertSame(0, NotificationDispatchRetry::query()->count());
    }

    public function test_the_kill_switch_sends_nothing_and_keeps_the_baseline_current(): void
    {
        config(['daily_roster.move_alerts.enabled' => false]);
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);
        $snapshot = $this->publish([self::RESCUE => [$this->row('90001')]]);

        $this->assertSame(0, $this->detect());

        Notification::assertNotSentTo($member, RosterMoveNotification::class);
        $this->assertSame((int) $snapshot->getKey(), $this->baselineSnapshotId());
    }

    public function test_it_does_nothing_outside_publish_mode(): void
    {
        config(['daily_roster.mode' => 'shadow']);
        $this->publish([self::RESCUE => [$this->row('90001')]]);

        $this->assertSame(0, $this->detect());
        $this->assertNull($this->baselineSnapshotId());
    }

    public function test_checking_never_moves_the_time_members_read_as_last_updated(): void
    {
        $this->baseline([self::ENGINE => [$this->row('90001')]]);
        $this->publish([self::RESCUE => [$this->row('90001')]]);
        $before = RosterActiveVersion::query()->whereDate('duty_date', self::TODAY)->firstOrFail()->updated_at;

        $this->travel(10)->minutes();
        $this->detect();

        $this->assertEquals($before, RosterActiveVersion::query()->whereDate('duty_date', self::TODAY)->firstOrFail()->updated_at);
    }

    public function test_old_baselines_are_removed_when_a_new_duty_day_starts(): void
    {
        RosterMoveBaseline::query()->insert(['duty_date' => '2026-09-01', 'roster_snapshot_id' => null, 'created_at' => now(), 'updated_at' => now()]);

        $this->baseline([self::ENGINE => [$this->row('90001')]]);

        $this->assertFalse(RosterMoveBaseline::query()->whereDate('duty_date', '2026-09-01')->exists());
    }

    public function test_a_verified_publish_queues_the_check(): void
    {
        Bus::fake([DetectRosterMoves::class]);
        $snapshot = $this->publish([self::ENGINE => [$this->row('90001')]]);
        // The factory stores a made-up hash and shift; the read-back recomputes both from the rows.
        $sections = app(CurrentRosterReader::class)->forSnapshot($snapshot)->sections;
        RosterSnapshot::query()->whereKey($snapshot->getKey())->toBase()->update(['normalized_sha256' => RosterPublicationVerifier::normalizedHash(self::TODAY, (string) $snapshot->shift_letter, $sections)]);
        $import = RosterImport::query()->findOrFail($snapshot->roster_import_id);
        $import->forceFill(['state' => RosterImport::STATE_PUBLISHED, 'verified_at' => null])->save();
        app(RosterStateRecorder::class)->appendEvent($import, RosterStateRecorder::ACTION_POINTER_MOVED);

        $this->assertTrue(app(RosterPublicationVerifier::class)->verify($import));

        Bus::assertDispatched(DetectRosterMoves::class);
    }

    public function test_the_reconciler_runs_the_same_check(): void
    {
        $member = $this->member('90001');
        $this->baseline([self::ENGINE => [$this->row('90001')]]);
        $this->publish([self::RESCUE => [$this->row('90001')]]);

        $this->artisan('daily-roster:reconcile')->assertSuccessful();

        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    private function detect(): int
    {
        return app(RosterMoveDetector::class)->run();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' '.$time, 'America/New_York'));
    }

    private function member(string $employeeId): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Member '.$employeeId,
            'rank' => 'Firefighter',
            'roster_status' => 'active',
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => $employeeId,
            'employee_profile_id' => $employee->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(string $employeeId, string $from = '08:00', string $through = '08:00', string $rank = 'Firefighter', string $code = 'SP', string $availability = RosterAssignment::AVAILABILITY_WORKING, string $category = 'Scheduled'): array
    {
        return [
            'employee_id' => $employeeId, 'name' => 'TESTER, '.$employeeId, 'rank' => $rank, 'code' => $code,
            'availability' => $availability, 'category' => $category, 'from' => $from, 'through' => $through,
        ];
    }

    /** @param array<string, list<array<string, mixed>>> $sections */
    private function publish(array $sections): RosterSnapshot
    {
        return DailyRosterSnapshotFactory::publish(self::TODAY, $sections, ['generated_at' => now()]);
    }

    /** @param array<string, list<array<string, mixed>>> $sections */
    private function baseline(array $sections): RosterSnapshot
    {
        $snapshot = $this->publish($sections);
        $this->detect();

        return $snapshot;
    }

    private function point(RosterSnapshot $snapshot): void
    {
        RosterActiveVersion::query()->whereDate('duty_date', self::TODAY)->update([
            'roster_snapshot_id' => $snapshot->getKey(),
            'content_changed_at' => now(),
        ]);
    }

    private function baselineSnapshotId(): ?int
    {
        $id = RosterMoveBaseline::query()->whereDate('duty_date', self::TODAY)->value('roster_snapshot_id');

        return $id === null ? null : (int) $id;
    }
}
