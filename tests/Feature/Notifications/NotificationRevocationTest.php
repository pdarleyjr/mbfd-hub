<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\DepartmentUpdatePriority;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\Station;
use App\Models\User;
use App\Notifications\DepartmentUpdateNotification;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/**
 * A role revoked (or an account disabled) after an alert was queued stops that alert,
 * and a saved preference never turns a non-eligible member into a recipient.
 */
final class NotificationRevocationTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
    }

    private function stationReviewer(): User
    {
        return $this->withPermissions($this->member(), 'admin.access', 'admin.stations.view');
    }

    private function stationRequestAlert(?string $eventKey = null): NewSubmissionNotification
    {
        return new NewSubmissionNotification(
            submissionType: 'station_request',
            title: 'New Station Request',
            body: 'SR-1: Leaking faucet',
            actionUrl: '/admin/station-requests/1',
            eventKey: $eventKey,
        );
    }

    /** Run every queued per-channel notification job exactly as the worker would. */
    private function runQueuedNotifications(): int
    {
        $jobs = Queue::pushed(SendQueuedNotifications::class);
        $jobs->each(fn (SendQueuedNotifications $job) => $job->handle(app(ChannelManager::class)));

        return $jobs->count();
    }

    public function test_an_alert_queued_for_an_eligible_member_is_delivered(): void
    {
        Queue::fake();
        $reviewer = $this->stationReviewer();

        app(NotificationDispatchService::class)->send('station_requests', $this->stationRequestAlert());
        $this->assertSame(1, $this->runQueuedNotifications());

        $this->assertSame(1, $reviewer->notifications()->count());
    }

    public function test_a_permission_revoked_after_the_alert_was_queued_stops_delivery(): void
    {
        Queue::fake();
        $reviewer = $this->stationReviewer();

        app(NotificationDispatchService::class)->send('station_requests', $this->stationRequestAlert());
        $reviewer->revokePermissionTo('admin.stations.view');
        $this->assertSame(1, $this->runQueuedNotifications(), 'it was queued while the member was still eligible');

        $this->assertSame(0, $reviewer->notifications()->count(), 'but the worker re-checked and delivered nothing');
    }

    public function test_a_disabled_account_stops_a_queued_alert(): void
    {
        Queue::fake();
        $reviewer = $this->stationReviewer();

        app(NotificationDispatchService::class)->send('station_requests', $this->stationRequestAlert());
        $reviewer->forceFill(['account_status' => 'disabled'])->save();
        $this->runQueuedNotifications();

        $this->assertSame(0, $reviewer->notifications()->count());
    }

    public function test_a_reviewer_role_revoked_after_a_checkout_alert_was_queued_stops_it(): void
    {
        Queue::fake();
        $reviewer = $this->withRole($this->member(), 'logistics_admin');
        $member = $this->member();
        $review = new InspectionEventNotification(InspectionNotificationKind::REVIEW_REQUIRED, 'Review needed', 'E1 needs approval', '/admin/inspections/1', 'inspection:1:review_required');
        $reply = new InspectionEventNotification(InspectionNotificationKind::INSPECTION_DECIDED, 'Decision', 'Your checkout was approved', '/employee/my-vehicle-inspections', 'inspection:1:decided');

        $reviewer->notify($review);
        $member->notify($reply);
        $reviewer->removeRole('logistics_admin');
        $this->runQueuedNotifications();

        $this->assertSame(0, $reviewer->notifications()->count(), 'a former reviewer no longer receives reviewer alerts');
        $this->assertSame(1, $member->notifications()->count(), 'the member-facing accountability message is unaffected');
    }

    public function test_a_department_update_stops_when_the_account_is_disabled_before_delivery(): void
    {
        Queue::fake();
        $member = $this->member();
        $notice = new DepartmentUpdateNotification(7, 'Drill moved', 'The drill moved to 0900.', DepartmentUpdatePriority::Normal, true, false);

        $member->notify($notice);
        $member->forceFill(['account_status' => 'disabled'])->save();
        $this->runQueuedNotifications();

        $this->assertSame(0, $member->notifications()->count());
    }

    public function test_a_saved_preference_never_authorizes_a_recipient(): void
    {
        Queue::fake();
        $outsider = $this->member();
        $outsider->notificationSubscriptions()->create(['event_key' => 'station_requests', 'database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => true]);
        $eligible = $this->stationReviewer();

        $sent = app(NotificationDispatchService::class)->send('station_requests', $this->stationRequestAlert());
        $this->runQueuedNotifications();

        $this->assertSame([$eligible->id], $sent->pluck('id')->all());
        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(1, $eligible->notifications()->count(), 'an eligible member with no saved row gets the in-app default');
    }

    public function test_an_eligible_members_saved_choice_can_only_narrow(): void
    {
        Queue::fake();
        $reviewer = $this->stationReviewer();
        $reviewer->notificationSubscriptions()->create(['event_key' => 'station_requests', 'database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false]);

        app(NotificationDispatchService::class)->send('station_requests', $this->stationRequestAlert());
        $this->runQueuedNotifications();

        $this->assertSame(0, $reviewer->notifications()->count());
    }

    public function test_the_same_event_is_delivered_once_per_person(): void
    {
        $reviewer = $this->stationReviewer();
        $key = NotificationDispatchService::eventKey('station_requests', 'station_request', 1, 'created');
        $dispatch = app(NotificationDispatchService::class);

        $dispatch->send('station_requests', $this->stationRequestAlert($key), eventKey: $key);
        $again = $dispatch->send('station_requests', $this->stationRequestAlert($key), eventKey: $key);

        $this->assertTrue($again->isEmpty());
        $this->assertSame(1, $reviewer->notifications()->count());

        $other = NotificationDispatchService::eventKey('station_requests', 'station_request', 2, 'created');
        $dispatch->send('station_requests', $this->stationRequestAlert($other), eventKey: $other);
        $this->assertSame(2, $reviewer->notifications()->count(), 'a different event is a different alert');
    }

    public function test_candidates_listed_twice_are_still_one_recipient_in_a_stable_order(): void
    {
        $first = $this->stationReviewer();
        $second = $this->stationReviewer();

        $recipients = app(\App\Services\Notifications\NotificationRecipientEvaluator::class)
            ->recipients('station_requests', [$second, $first, $second]);

        $this->assertSame([$first->id, $second->id], $recipients->pluck('id')->all());
    }

    public function test_the_in_app_copy_records_why_the_member_received_it(): void
    {
        $reviewer = $this->stationReviewer();
        $key = NotificationDispatchService::eventKey('station_requests', 'station_request', 9, 'created');

        app(NotificationDispatchService::class)->send('station_requests', $this->stationRequestAlert($key), eventKey: $key);

        $provenance = $reviewer->notifications()->sole()->data['provenance'];
        $this->assertSame('station_requests', $provenance['category']);
        $this->assertSame('permission:admin.stations.view', $provenance['rule']);
        $this->assertSame($key, $provenance['event_key']);
    }

    public function test_maintenance_alerts_go_to_fleet_reviewers_only_and_never_to_an_email_guess(): void
    {
        $reviewer = $this->withRole($this->member(['email' => 'reviewer@example.test']), 'logistics_admin');
        $lookalike = $this->member(['email' => 'admin.person@example.test']);
        $pending = User::factory()->create(['email' => 'admin@example.test']);
        $this->withRole($pending, 'logistics_admin');
        $optedOut = $this->withRole($this->member(), 'logistics_admin');
        $optedOut->notificationSubscriptions()->create(['event_key' => 'vehicle_maintenance_alerts', 'database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false]);
        $station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1051 Jefferson Avenue', 'is_active' => true]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'E1', 'name' => 'Engine 1', 'type' => 'Engine', 'vehicle_number' => '1001',
            'designation' => 'E1', 'slug' => 'engine-1', 'status' => 'In Service', 'current_engine_hours' => 410.5, 'current_miles' => 32100,
        ]);

        (new PmAlertNotificationJob($apparatus->id, 'green'))->handle();

        $this->assertSame(1, $reviewer->notifications()->count());
        $this->assertSame(0, $lookalike->notifications()->count(), 'an email that merely contains "admin" is not a recipient');
        $this->assertSame(0, $pending->notifications()->count());
        $this->assertSame(0, $optedOut->notifications()->count(), 'the member turned in-app off');
    }
}
