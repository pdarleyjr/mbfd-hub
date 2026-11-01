<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind;
use App\Services\Narcotics\NarcoticsNotifier;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Support\Captain5\DutyCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

final class NarcoticsCheckNotificationsTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
    }

    private function notificationFor(User $user, string $kind): InspectionEventNotification
    {
        return Notification::sent($user, InspectionEventNotification::class)->first(fn (InspectionEventNotification $n): bool => $n->kind === $kind);
    }

    public function test_a_new_check_goes_to_captain_5_once_and_to_nobody_else(): void
    {
        $check = $this->submitOk($this->member);
        $key = "narcotics:check:{$check->id}:submitted";

        $this->assertSame(1, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_SUBMITTED, $key));
        foreach ([$this->member, $this->otherMember, $this->officer, $this->administrator, $this->superAdmin] as $outsider) {
            $this->assertSame(0, $this->sentTimes($outsider, NarcoticsNotifier::KIND_SUBMITTED), "{$outsider->name} must not be told");
        }
        $ledger = DB::table('inspection_notification_deliveries')->where('event_key', $key)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame($this->captain5->id, (int) $ledger->first()->recipient_user_id);
        $this->assertSame('dispatched', $ledger->first()->status);
    }

    public function test_captain_5_sending_their_own_check_is_not_notified_of_it(): void
    {
        $this->submitOk($this->captain5);

        $this->assertSame(0, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_SUBMITTED));
    }

    public function test_a_captain_5_who_can_no_longer_review_is_skipped(): void
    {
        DB::table('users')->where('id', $this->captain5->id)->update(['must_change_password' => true]);
        $this->forgetEmsMemo();

        $this->submitOk($this->member);

        $this->assertSame(0, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_SUBMITTED));
    }

    public function test_with_seat_access_on_a_new_check_goes_to_whoever_the_roster_has_working_the_seat(): void
    {
        config()->set('daily_roster.card_display_enabled', true);
        config()->set('daily_roster.seat_access_enabled', true);
        $rider = $this->emsMember('Firefighter', 'Rider Five');
        DailyRosterSnapshotFactory::publish(app(DutyCalendar::class)->dutyDate()->format('Y-m-d'), ['Rescue Division / Captain 5' => [
            ['employee_id' => $rider->employee_id, 'name' => 'FIVE, RIDER', 'rank' => 'FF', 'code' => '+OT', 'category' => 'Overtime'],
        ]]);
        $this->forgetEmsMemo();

        $this->submitOk($this->member);

        $this->assertSame(1, $this->sentTimes($rider, NarcoticsNotifier::KIND_SUBMITTED));
        $this->assertSame(0, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_SUBMITTED));
    }

    public function test_with_seat_access_on_a_check_still_reaches_a_seat_holder_when_nobody_on_the_roster_can_review(): void
    {
        config()->set('daily_roster.card_display_enabled', true);
        config()->set('daily_roster.seat_access_enabled', true);
        DailyRosterSnapshotFactory::publish(app(DutyCalendar::class)->dutyDate()->format('Y-m-d'), ['Rescue Division / Captain 5' => [
            ['kind' => 'vacancy_open', 'rank' => 'Capt'],
        ]]);
        $this->forgetEmsMemo();

        $this->submitOk($this->member);

        $this->assertSame(1, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_SUBMITTED));
    }

    public function test_the_member_hears_the_decision_and_only_the_member(): void
    {
        $approved = $this->submitOk($this->member);
        $rejected = $this->submitOk($this->member);
        $this->approve($this->captain5, $approved)->assertOk();
        $this->reject($this->captain5, $rejected, 'EXPLANATION-TEXT')->assertOk();

        $this->assertSame(2, $this->sentTimes($this->member, NarcoticsNotifier::KIND_DECISION));
        $titles = Notification::sent($this->member, InspectionEventNotification::class)->filter(fn ($n) => $n->kind === NarcoticsNotifier::KIND_DECISION)->pluck('title')->all();
        sort($titles);
        $this->assertSame(['Narcotics check approved', 'Narcotics check rejected'], $titles);
        foreach ([$this->otherMember, $this->officer, $this->administrator] as $other) {
            $this->assertSame(0, $this->sentTimes($other, NarcoticsNotifier::KIND_DECISION));
        }
    }

    public function test_no_notification_carries_the_note_the_explanation_a_photo_or_a_file_path(): void
    {
        $check = $this->submitOk($this->member, ['note' => 'SECRET-NOTE-9931']);
        $this->reject($this->captain5, $check, 'SECRET-EXPLANATION-4417')->assertOk();

        $all = Notification::sent($this->captain5, InspectionEventNotification::class)
            ->merge(Notification::sent($this->member, InspectionEventNotification::class));
        $this->assertCount(2, $all);
        foreach ($all as $notification) {
            $text = $notification->title.' | '.$notification->body.' | '.$notification->url;
            $this->assertStringNotContainsString('SECRET-NOTE-9931', $text);
            $this->assertStringNotContainsString('SECRET-EXPLANATION-4417', $text);
            $this->assertStringNotContainsString('narcotics-checks/', $text, 'no storage path');
            $this->assertStringNotContainsString('/image/', $text, 'no photo link');
            $this->assertStringNotContainsString($check->image_sha256, $text);
        }
    }

    public function test_the_lock_screen_text_names_nothing(): void
    {
        $check = $this->submitOk($this->member);
        $this->approve($this->captain5, $check)->assertOk();

        foreach ([[$this->captain5, NarcoticsNotifier::KIND_SUBMITTED], [$this->member, NarcoticsNotifier::KIND_DECISION]] as [$user, $kind]) {
            $push = $this->notificationFor($user, $kind)->pushText();
            $this->assertSame(['title' => 'MBFD Hub update', 'body' => 'Open MBFD Hub for details.'], $push);
        }
    }

    public function test_the_email_is_as_generic_as_the_lock_screen(): void
    {
        $check = $this->submitOk($this->member, ['note' => 'Box 1 seal broken']);
        $this->approve($this->captain5, $check)->assertOk();

        foreach ([[$this->captain5, NarcoticsNotifier::KIND_SUBMITTED], [$this->member, NarcoticsNotifier::KIND_DECISION]] as [$user, $kind]) {
            $email = $this->notificationFor($user, $kind)->toBudgetedEmail($user);
            $this->assertSame('MBFD Hub update', $email['subject']);
            foreach (['text', 'html'] as $part) {
                $this->assertStringNotContainsString('Pat Member', $email[$part]);
                $this->assertStringNotContainsString('Box 1', $email[$part]);
            }
            $this->assertStringStartsWith('Open MBFD Hub for details.', $email['text']);
        }
    }

    public function test_the_links_open_the_right_place_for_each_audience_and_carry_only_the_check_id(): void
    {
        $check = $this->submitOk($this->member);
        $this->approve($this->captain5, $check)->assertOk();

        $this->assertSame("/daily/captain-5?tab=narcotics&check={$check->uuid}", $this->notificationFor($this->captain5, NarcoticsNotifier::KIND_SUBMITTED)->url);
        $this->assertSame("/employee/forms?form=narcotics-check&check={$check->uuid}", $this->notificationFor($this->member, NarcoticsNotifier::KIND_DECISION)->url);
    }

    public function test_the_kinds_belong_to_registry_categories_so_each_person_can_choose_how_to_be_reached(): void
    {
        $table = InspectionNotificationKind::categoryTable();

        $this->assertSame(NotificationCategoryRegistry::CAPTAIN5_EMS, $table[NarcoticsNotifier::KIND_SUBMITTED]);
        $this->assertSame(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, $table[NarcoticsNotifier::KIND_DECISION]);
    }

    public function test_a_failed_delivery_can_be_retried_to_the_same_person_only(): void
    {
        $check = $this->submitOk($this->member);
        $key = "narcotics:check:{$check->id}:submitted";
        Notification::fake(); // forget the first delivery: this is the retry

        $this->assertTrue(app(NarcoticsNotifier::class)->redeliver($key, NarcoticsNotifier::KIND_SUBMITTED, $this->captain5->id));
        $this->assertSame(1, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_SUBMITTED, $key));

        $this->assertFalse(app(NarcoticsNotifier::class)->redeliver($key, NarcoticsNotifier::KIND_SUBMITTED, $this->officer->id), 'a non-reviewer is never a retry recipient');
        $this->assertFalse(app(NarcoticsNotifier::class)->redeliver('narcotics:check:999999:submitted', NarcoticsNotifier::KIND_SUBMITTED, $this->captain5->id));
        $this->assertFalse(app(NarcoticsNotifier::class)->redeliver('narcotics:nonsense', NarcoticsNotifier::KIND_SUBMITTED, $this->captain5->id));
        $this->assertSame(0, $this->sentTimes($this->officer, NarcoticsNotifier::KIND_SUBMITTED));
    }

    public function test_the_decision_can_be_retried_to_the_member(): void
    {
        $check = $this->submitOk($this->member);
        $this->approve($this->captain5, $check)->assertOk();
        $event = $check->events()->where('action', 'approved')->firstOrFail();
        $key = "narcotics:check:{$check->id}:decided:{$event->id}";
        Notification::fake();

        $this->assertTrue(app(NarcoticsNotifier::class)->redeliver($key, NarcoticsNotifier::KIND_DECISION, $this->member->id));
        $this->assertSame(1, $this->sentTimes($this->member, NarcoticsNotifier::KIND_DECISION, $key));
        $this->assertFalse(app(NarcoticsNotifier::class)->redeliver($key, NarcoticsNotifier::KIND_DECISION, $this->captain5->id));
    }

    public function test_the_retry_command_routes_a_stuck_narcotics_ledger_row_to_this_notifier(): void
    {
        $check = $this->submitOk($this->member);
        $key = "narcotics:check:{$check->id}:submitted";
        DB::table('inspection_notification_deliveries')->where('event_key', $key)->update(['status' => 'queued', 'updated_at' => now()->subMinutes(30)]);
        Notification::fake();

        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        $this->assertSame(1, $this->sentTimes($this->captain5, NarcoticsNotifier::KIND_SUBMITTED, $key));
        $this->assertInstanceOf(NarcoticsCheck::class, $check->fresh());
    }
}
