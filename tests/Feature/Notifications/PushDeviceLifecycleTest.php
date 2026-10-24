<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Employee;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Notifications\TestPushNotification;
use App\Services\Notifications\PushDeviceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\Feature\Notifications\Concerns\FakeWebPush;
use Tests\TestCase;

final class PushDeviceLifecycleTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const PHONE = 'https://fcm.googleapis.com/fcm/send/phone';

    private const LAPTOP = 'https://updates.push.services.mozilla.com/wpush/v2/laptop';

    private const TABLET = 'https://web.push.apple.com/tablet-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Route the real WebPushChannel through a push service that answers as configured. */
    private function pushService(array $statusByEndpoint = [], array $headers = []): FakeWebPush
    {
        $service = new FakeWebPush($statusByEndpoint, $headers);
        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): FakeWebPush => $service);

        return $service;
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

    private function sendTest(User $user, ?array $endpoints = null): void
    {
        $user->notifyNow(new TestPushNotification($endpoints));
    }

    public function test_one_alert_reaches_every_device_the_member_has(): void
    {
        $user = $this->member();
        $service = $this->pushService();
        foreach ([self::PHONE, self::LAPTOP, self::TABLET] as $endpoint) {
            $this->device($user, $endpoint);
        }

        $this->sendTest($user);

        $this->assertEqualsCanonicalizing([self::PHONE, self::LAPTOP, self::TABLET], $service->delivered);
        $user->pushSubscriptions()->get()->each(function (HubPushSubscription $device): void {
            $this->assertNotNull($device->last_success_at);
            $this->assertSame(0, $device->failure_count);
        });
    }

    public function test_an_expired_endpoint_is_removed_and_the_other_devices_still_get_the_alert(): void
    {
        $user = $this->member();
        $service = $this->pushService([self::PHONE => 410, self::LAPTOP => 404]);
        foreach ([self::PHONE, self::LAPTOP, self::TABLET] as $endpoint) {
            $this->device($user, $endpoint);
        }

        $this->sendTest($user);

        $this->assertEqualsCanonicalizing([self::TABLET], $user->pushSubscriptions()->pluck('endpoint')->all(), '404 and 410 both mean the device is gone');
        $this->assertCount(3, $service->delivered);
        $this->assertNotNull($user->pushSubscriptions()->sole()->last_success_at);
    }

    public function test_a_rate_limited_device_is_kept_counted_and_backed_off_while_healthy_ones_continue(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = $this->member();
        $this->pushService([self::PHONE => 429], [self::PHONE => ['Retry-After' => '600']]);
        $this->device($user, self::PHONE);
        $this->device($user, self::LAPTOP);

        $this->sendTest($user);

        $limited = $user->pushSubscriptions()->where('endpoint', self::PHONE)->sole();
        $this->assertSame(1, $limited->failure_count);
        $this->assertStringContainsString('rate_limited', (string) $limited->last_failure_reason);
        $this->assertNotNull($limited->last_failure_at);
        $this->assertTrue($limited->backoff_until->greaterThanOrEqualTo(now()->addMinutes(10)), 'honours Retry-After');
        $this->assertTrue($limited->backoff_until->lessThanOrEqualTo(now()->addMinutes(15)));
        $this->assertSame(0, $user->pushSubscriptions()->where('endpoint', self::LAPTOP)->sole()->failure_count);

        // Backed off: normal alerts skip it, but it is still listed and can be tested on purpose.
        $this->assertSame([self::LAPTOP], $user->routeNotificationForWebPush()->pluck('endpoint')->all());
        $this->assertSame(2, $user->pushSubscriptions()->count());
    }

    public function test_repeated_server_errors_back_off_longer_and_a_success_clears_the_streak(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = $this->member();
        $service = $this->pushService([self::PHONE => 503]);
        $this->device($user, self::PHONE);

        $this->sendTest($user);
        $first = $user->pushSubscriptions()->sole();
        $this->sendTest($user);
        $second = $user->pushSubscriptions()->sole();

        $this->assertSame(2, $second->failure_count);
        $this->assertTrue($second->backoff_until->greaterThan($first->backoff_until));
        $this->assertStringContainsString('push_service_error', (string) $second->last_failure_reason);

        $service->statusByEndpoint = [];
        $this->sendTest($user);
        $healed = $user->pushSubscriptions()->sole();
        $this->assertSame(0, $healed->failure_count);
        $this->assertNull($healed->backoff_until);
        $this->assertNull($healed->last_failure_reason);
        $this->assertNotNull($healed->last_success_at);
    }

    public function test_a_device_is_unhealthy_only_while_it_waits_out_a_back_off_and_is_probed_again_afterwards(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = $this->member();
        $device = $this->device($user, self::PHONE);
        $evaluator = app(\App\Services\Notifications\NotificationRecipientEvaluator::class);
        $this->assertTrue(PushDeviceHealth::scopeHealthy($user->pushSubscriptions())->exists());

        $device->forceFill(['failure_count' => 40, 'backoff_until' => now()->addMinutes(15)])->save();
        $this->assertFalse(PushDeviceHealth::scopeHealthy($user->pushSubscriptions())->exists());
        $this->assertTrue($evaluator->hasDevice($user), 'a device in back-off is still a device: the push channel stays in the decision');

        Carbon::setTestNow('2026-10-23 12:15:01');
        $this->assertTrue(PushDeviceHealth::scopeHealthy($user->pushSubscriptions())->exists(), 'a long-failing device is probed again, never retired for good');
    }

    public function test_a_refusal_of_the_hubs_own_request_is_logged_and_counted_but_never_puts_the_device_on_back_off(): void
    {
        $users = [];
        $statuses = [];
        foreach ([400, 401, 403, 413] as $status) {
            $user = $this->member();
            $this->device($user, self::PHONE.$status);
            $users[$status] = $user;
            $statuses[self::PHONE.$status] = $status;
        }
        $this->pushService($statuses);

        foreach ($users as $status => $user) {
            $this->sendTest($user);

            $device = $user->pushSubscriptions()->sole();
            $this->assertSame(1, $device->failure_count, "HTTP {$status} is counted as the Hub's own error");
            $this->assertStringContainsString("({$status})", (string) $device->last_failure_reason);
            $this->assertNull($device->backoff_until, "HTTP {$status} never puts a healthy phone on back-off");
        }
    }

    public function test_the_test_notification_goes_only_to_the_requesting_members_own_devices(): void
    {
        $service = $this->pushService();
        $me = $this->member();
        $someoneElse = $this->member();
        $this->device($me, self::PHONE);
        $this->device($me, self::LAPTOP);
        $this->device($someoneElse, self::TABLET);

        $this->actingAs($me)
            ->postJson('/api/push/test')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'devices');

        $this->assertEqualsCanonicalizing([self::PHONE, self::LAPTOP], $service->delivered);
        $this->assertNotContains(self::TABLET, $service->delivered);
    }

    public function test_the_test_notification_can_target_one_device_but_never_another_members(): void
    {
        $service = $this->pushService();
        $me = $this->member();
        $someoneElse = $this->member();
        $this->device($me, self::PHONE);
        $this->device($me, self::LAPTOP);
        $this->device($someoneElse, self::TABLET);

        $this->actingAs($me)->postJson('/api/push/test', ['endpoint' => self::LAPTOP])->assertOk();
        $this->assertSame([self::LAPTOP], $service->delivered);

        $this->actingAs($me)->postJson('/api/push/test', ['endpoint' => self::TABLET])
            ->assertStatus(400);
        $this->assertNotContains(self::TABLET, $service->delivered, 'a device that is not mine is never reachable through my test button');
    }

    public function test_a_member_with_no_device_gets_a_plain_error_from_the_test_button(): void
    {
        $this->actingAs($this->member())->postJson('/api/push/test')->assertStatus(400)->assertJsonStructure(['error']);
    }

    public function test_the_test_notification_is_rate_limited(): void
    {
        $this->pushService();
        $me = $this->member();
        $this->device($me, self::PHONE);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->actingAs($me)->postJson('/api/push/test')->assertOk();
        }

        $this->actingAs($me)->postJson('/api/push/test')->assertTooManyRequests();
    }

    public function test_the_test_notification_requires_a_signed_in_member(): void
    {
        $this->postJson('/api/push/test')->assertUnauthorized();
    }

    public function test_disabling_an_account_deletes_its_push_devices(): void
    {
        $user = $this->member();
        $keeper = $this->member();
        $this->device($user, self::PHONE);
        $this->device($keeper, self::LAPTOP);

        $user->forceFill(['account_status' => 'disabled'])->save();

        $this->assertSame(0, $user->pushSubscriptions()->count());
        $this->assertSame(1, $keeper->pushSubscriptions()->count());
    }

    public function test_a_roster_departure_deletes_the_members_push_devices(): void
    {
        $user = $this->rosterMember();
        $this->device($user, self::PHONE);

        Employee::query()->whereKey($user->employee_profile_id)->firstOrFail()->forceFill(['roster_status' => 'departed'])->save();

        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_becoming_active_does_not_delete_devices(): void
    {
        $user = User::factory()->create();
        $this->device($user, self::PHONE);

        $user->forceFill(['account_status' => 'active'])->save();

        $this->assertSame(1, $user->pushSubscriptions()->count());
    }

    public function test_a_real_alert_skips_a_backed_off_device_and_uses_the_healthy_one(): void
    {
        $service = $this->pushService();
        $user = $this->withPermissions($this->member(), 'admin.access', 'admin.stations.view');
        $user->notificationSubscriptions()->create(['event_key' => 'station_requests', 'database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => false]);
        $backedOff = $this->device($user, self::PHONE);
        $backedOff->forceFill(['failure_count' => 2, 'backoff_until' => now()->addHour()])->save();
        $this->device($user, self::LAPTOP);

        $user->notifyNow(new NewSubmissionNotification('station_request', 'New request', 'Body', '/admin/station-requests/1'));

        $this->assertSame([self::LAPTOP], $service->delivered);
        $this->assertSame(1, $user->notifications()->count(), 'the in-app copy is delivered as well');
    }

    public function test_in_app_delivery_remains_when_every_push_device_is_unusable(): void
    {
        $service = $this->pushService();
        $user = $this->withPermissions($this->member(), 'admin.access', 'admin.stations.view');
        $user->notificationSubscriptions()->create(['event_key' => 'station_requests', 'database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => false]);
        $this->device($user, self::PHONE)->forceFill(['failure_count' => 20, 'backoff_until' => now()->addHours(2)])->save();

        $user->notifyNow(new NewSubmissionNotification('station_request', 'New request', 'Body', '/admin/station-requests/1'));

        $this->assertSame([], $service->delivered);
        $this->assertSame(1, $user->notifications()->count());
    }
}
