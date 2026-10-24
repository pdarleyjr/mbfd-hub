<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverDeferredPush;
use App\Jobs\DeliverDepartmentUpdateNotification;
use App\Jobs\SendDepartmentUpdateNotification;
use App\Models\DepartmentUpdate;
use App\Models\DepartmentUpdateNotificationDelivery;
use App\Models\Employee;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\DepartmentUpdates\DepartmentUpdateAudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Role;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\Feature\Notifications\Concerns\FakeWebPush;
use Tests\TestCase;

/**
 * A push service that is busy (429/5xx) delays a device; it never removes the alert, the channel or the
 * in-app copy. Refusals of the Hub's own request (400/401/403/413) never put a device on back-off.
 */
final class PushBackoffDeferralTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const PHONE = 'https://fcm.googleapis.com/fcm/send/phone';

    private const LAPTOP = 'https://updates.push.services.mozilla.com/wpush/v2/laptop';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, int> $statusByEndpoint @param array<string, array<string, string>> $headers */
    private function pushService(array $statusByEndpoint = [], array $headers = []): FakeWebPush
    {
        $service = new FakeWebPush($statusByEndpoint, $headers);
        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): FakeWebPush => $service);

        return $service;
    }

    private function reviewer(): User
    {
        $user = $this->withPermissions($this->member(), 'admin.access', 'admin.stations.view');
        $user->notificationSubscriptions()->create(['event_key' => 'station_requests', 'database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => false]);

        return $user;
    }

    private function device(User $user, string $endpoint): HubPushSubscription
    {
        /** @var HubPushSubscription $device */
        $device = $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
        ]);

        return $device;
    }

    private function alert(int $n): NewSubmissionNotification
    {
        return new NewSubmissionNotification('station_request', "Request {$n}", 'Body', '/admin/station-requests/'.$n);
    }

    /** @return list<DeliverDeferredPush> */
    private function deferredJobs(): array
    {
        return Queue::pushed(DeliverDeferredPush::class)->values()->all();
    }

    public function test_a_burst_with_a_429_delivers_in_app_for_every_alert_and_retries_the_push_after_the_back_off(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService([self::PHONE => 429]);
        $user = $this->reviewer();
        $phone = $this->device($user, self::PHONE);

        foreach ([1, 2, 3] as $n) {
            $user->notifyNow($this->alert($n));
        }

        $this->assertSame(3, $user->notifications()->count(), 'the in-app copy is delivered for every alert of the burst');
        $this->assertSame([self::PHONE], $service->delivered, 'only the first alert was attempted; the others waited out the back-off');
        $this->assertCount(3, $this->deferredJobs(), 'the refused alert and the two that waited are all re-queued');
        Queue::assertPushed(DeliverDeferredPush::class, fn (DeliverDeferredPush $job): bool => $job->delay !== null
            && $job->subscriptionId === $phone->id && $job->queue === 'notifications');

        // The push service recovers; the retries run after the back-off.
        $service->statusByEndpoint = [];
        Carbon::setTestNow('2026-10-24 12:16:00');
        foreach ($this->deferredJobs() as $job) {
            $job->handle();
        }

        $this->assertSame([self::PHONE, self::PHONE, self::PHONE, self::PHONE], $service->delivered);
        $phone->refresh();
        $this->assertNull($phone->backoff_until);
        $this->assertSame(0, $phone->failure_count);
    }

    public function test_a_healthy_device_still_gets_the_alert_now_while_the_backed_off_one_is_deferred(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService();
        $user = $this->reviewer();
        $this->device($user, self::PHONE)->forceFill(['failure_count' => 1, 'backoff_until' => now()->addMinutes(3)])->save();
        $this->device($user, self::LAPTOP);

        $user->notifyNow($this->alert(1));

        $this->assertSame([self::LAPTOP], $service->delivered);
        $this->assertCount(1, $this->deferredJobs());
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_a_deferred_alert_waits_again_when_the_device_is_still_in_back_off_and_stops_when_access_is_revoked(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService();
        $user = $this->reviewer();
        $this->device($user, self::PHONE)->forceFill(['failure_count' => 1, 'backoff_until' => now()->addMinutes(3)])->save();
        $user->notifyNow($this->alert(1));
        [$job] = $this->deferredJobs();

        // Too early: the job re-queues itself instead of sending.
        $job->handle();
        $this->assertSame([], $service->delivered);
        $this->assertCount(2, $this->deferredJobs());

        // The member loses the permission while the alert waits: nothing is sent.
        Carbon::setTestNow('2026-10-24 12:10:00');
        $user->revokePermissionTo('admin.stations.view');
        $this->resetPermissionCache();
        $job->handle();
        $this->assertSame([], $service->delivered);
    }

    public function test_a_device_removed_while_the_alert_waited_receives_nothing(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService();
        $user = $this->reviewer();
        $this->device($user, self::PHONE)->forceFill(['failure_count' => 1, 'backoff_until' => now()->addMinutes(3)])->save();
        $user->notifyNow($this->alert(1));
        [$job] = $this->deferredJobs();

        $user->pushSubscriptions()->delete();
        Carbon::setTestNow('2026-10-24 12:10:00');
        $job->handle();

        $this->assertSame([], $service->delivered);
    }

    public function test_a_refusal_of_the_hubs_own_request_is_not_retried_and_does_not_silence_the_device(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService([self::PHONE => 413]);
        $user = $this->reviewer();
        $phone = $this->device($user, self::PHONE);

        $user->notifyNow($this->alert(1));
        $user->notifyNow($this->alert(2));

        $this->assertSame([self::PHONE, self::PHONE], $service->delivered, 'no back-off: the next alert is still attempted');
        Queue::assertNotPushed(DeliverDeferredPush::class);
        $phone->refresh();
        $this->assertNull($phone->backoff_until);
        $this->assertSame(2, $phone->failure_count);
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_a_server_error_backs_off_for_a_minute_and_a_retry_after_is_honoured_but_capped(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $this->pushService([self::PHONE => 503, self::LAPTOP => 429], [self::LAPTOP => ['Retry-After' => '7200']]);
        $user = $this->reviewer();
        $phone = $this->device($user, self::PHONE);
        $user->notifyNow($this->alert(1));

        $this->assertEquals(now()->addMinute(), $phone->refresh()->backoff_until);

        $other = $this->reviewer();
        $laptop = $this->device($other, self::LAPTOP);
        $other->notifyNow($this->alert(2));

        $this->assertEquals(now()->addMinutes(15), $laptop->refresh()->backoff_until, 'a two-hour Retry-After is capped at fifteen minutes');
    }

    public function test_a_backup_alert_to_a_backed_off_administrator_is_not_lost(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService();
        Role::findOrCreate('super_admin', 'web');
        $admin = $this->withRole($this->member(), 'super_admin');
        $this->device($admin, self::PHONE)->forceFill(['failure_count' => 1, 'backoff_until' => now()->addMinutes(4)])->save();

        $this->artisan('mbfd:backup-alert', ['kind' => 'backup_failure'])->assertExitCode(0);

        $this->assertSame(1, $admin->notifications()->count(), 'the bell copy is there');
        $this->assertSame([], $service->delivered, 'the device is waiting out its back-off');
        $this->assertCount(1, $this->deferredJobs(), 'the push is queued for after the back-off');

        Carbon::setTestNow('2026-10-24 12:05:00');
        $this->deferredJobs()[0]->handle();
        $this->assertSame([self::PHONE], $service->delivered);
    }

    public function test_a_department_update_push_row_for_a_device_in_back_off_is_deferred_not_cancelled(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService();
        $author = User::factory()->create();
        $employee = Employee::query()->create([
            'employee_id' => '7001', 'name' => 'Test Firefighter', 'rank' => 'Firefighter',
            'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);
        $recipient = $this->member(['employee_id' => '7001', 'employee_profile_id' => $employee->id]);
        $recipient->notificationSubscriptions()->create(['event_key' => 'department_updates', 'database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => false]);
        $this->device($recipient, self::PHONE)->forceFill(['failure_count' => 2, 'backoff_until' => now()->addMinutes(5)])->save();
        $update = DepartmentUpdate::query()->create([
            'title' => 'Urgent notice', 'body' => '<p>Read this.</p>', 'category' => 'operations', 'priority' => 'important',
            'status' => 'published', 'publish_at' => now()->subMinute(), 'send_in_app' => false, 'send_web_push' => true,
            'audience' => 'everyone', 'author_id' => $author->id,
        ]);
        (new SendDepartmentUpdateNotification($update->id))->handle(app(DepartmentUpdateAudienceResolver::class));
        $delivery = DepartmentUpdateNotificationDelivery::query()->where('user_id', $recipient->id)->sole();

        (new DeliverDepartmentUpdateNotification($delivery->id))->handle();

        $delivery->refresh();
        $this->assertNull($delivery->cancelled_at, 'a transient back-off is not a permanent cancellation');
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame([], $service->delivered);
        $this->assertCount(1, $this->deferredJobs(), 'the push waits for the back-off to end');

        Carbon::setTestNow('2026-10-24 12:06:00');
        $this->deferredJobs()[0]->handle();
        $this->assertSame([self::PHONE], $service->delivered);
    }
}
