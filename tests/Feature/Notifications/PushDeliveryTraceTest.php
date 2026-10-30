<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverDeferredPush;
use App\Jobs\FanOutNotification;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Notifications\TestPushNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\PushDeliveryOutcomeRecorder;
use App\Services\Notifications\PushDeliveryTrace;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\Feature\Notifications\Concerns\CapturesPushLog;
use Tests\Feature\Notifications\Concerns\FakeWebPush;
use Tests\TestCase;

/**
 * Every push attempt leaves one structured, privacy-preserving log line: who, which device (by a short
 * hash), which push service, what encoding, what the service answered, and a correlation id that follows
 * an alert from its fan-out into its deferred retries. Nothing in the log can be used to push to a device.
 */
final class PushDeliveryTraceTest extends TestCase
{
    use BuildsNotificationPersonas;
    use CapturesPushLog;
    use RefreshDatabase;

    private const PUBLIC_KEY = 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const AUTH = 'AUTHSECRETAAAAAAAAAAAA';

    private const IPHONE = 'https://web.push.apple.com/SECRET-IPHONE-TOKEN';

    private const PHONE = 'https://fcm.googleapis.com/fcm/send/SECRET-FCM-TOKEN';

    private const LAPTOP = 'https://updates.push.services.mozilla.com/wpush/v2/SECRET-MOZILLA-TOKEN';

    private const WINDOWS = 'https://wns2-ch1p.notify.windows.com/w/?token=SECRET-WNS-TOKEN';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->captureLog();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, int> $statusByEndpoint */
    private function pushService(array $statusByEndpoint = []): FakeWebPush
    {
        $service = new FakeWebPush($statusByEndpoint);
        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): FakeWebPush => $service);

        return $service;
    }

    private function reviewer(): User
    {
        $user = $this->withPermissions($this->member(), 'admin.access', 'admin.stations.view');
        $user->notificationSubscriptions()->create(['event_key' => 'station_requests', 'database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => false]);

        return $user;
    }

    private function device(User $user, string $endpoint, ?string $encoding = null): HubPushSubscription
    {
        /** @var HubPushSubscription $device */
        $device = $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => self::PUBLIC_KEY,
            'auth_token' => self::AUTH,
            'content_encoding' => $encoding,
            'device_label' => 'Peter private iPhone label',
        ]);

        return $device;
    }

    private function alert(int $n = 1): NewSubmissionNotification
    {
        return new NewSubmissionNotification('station_request', "Request {$n}", 'Private message body', '/admin/station-requests/'.$n);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function providerFamilies(): iterable
    {
        yield 'apple' => ['https://web.push.apple.com/abc', 'apple'];
        yield 'apple sandbox host' => ['https://api.sandbox.push.apple.com/3/device/abc', 'apple'];
        yield 'fcm' => ['https://fcm.googleapis.com/fcm/send/abc', 'fcm'];
        yield 'legacy google' => ['https://android.googleapis.com/gcm/send/abc', 'fcm'];
        yield 'mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/abc', 'mozilla'];
        yield 'wns regional host' => ['https://wns2-ch1p.notify.windows.com/w/?token=abc', 'wns'];
        yield 'unknown host' => ['https://push.example.test/abc', 'other'];
        yield 'lookalike suffix' => ['https://web.push.apple.com.evil.test/abc', 'other'];
        yield 'lookalike prefix' => ['https://evilnotify.windows.com.example/abc', 'other'];
        yield 'not a url' => ['not a url', 'other'];
        yield 'empty' => ['', 'other'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerFamilies')]
    public function test_the_provider_family_comes_from_the_endpoint_host_only(string $endpoint, string $family): void
    {
        $this->assertSame($family, PushDeliveryTrace::providerFamily($endpoint));
    }

    public function test_the_endpoint_hash_is_ten_characters_of_the_sha256_and_not_reversible_to_an_address(): void
    {
        $hash = PushDeliveryTrace::endpointHash(self::IPHONE);

        $this->assertSame(substr(hash('sha256', self::IPHONE), 0, 10), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $hash);
    }

    public function test_an_accepted_attempt_logs_every_field_the_support_questions_need(): void
    {
        $this->pushService();
        $user = $this->reviewer();
        $iphone = $this->device($user, self::IPHONE);

        $notification = $this->alert();
        $user->notifyNow($notification);

        $lines = $this->attemptLines();
        $this->assertCount(1, $lines);
        $line = $lines[0];

        $this->assertSame(NewSubmissionNotification::class, $line['notification_class']);
        $this->assertSame('station_requests', $line['category_key']);
        $this->assertSame($user->notifications()->sole()->id, $line['notification_id']);
        $this->assertSame($user->id, $line['user_id']);
        $this->assertSame($iphone->id, $line['device_id']);
        $this->assertSame(PushDeliveryTrace::endpointHash(self::IPHONE), $line['endpoint_hash']);
        $this->assertSame('apple', $line['provider']);
        $this->assertSame('aes128gcm', $line['content_encoding'], 'a device with no stored encoding is logged as what it is actually sent');
        $this->assertSame(1, $line['attempt']);
        $this->assertTrue($line['attempted']);
        $this->assertSame(201, $line['provider_status']);
        $this->assertSame('accepted', $line['outcome']);
        $this->assertIsInt($line['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $line['duration_ms']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $line['correlation_id']);
    }

    public function test_a_stored_aesgcm_encoding_is_logged_as_aesgcm(): void
    {
        $this->pushService();
        $user = $this->reviewer();
        $this->device($user, self::PHONE, 'aesgcm');

        $user->notifyNow($this->alert());

        $this->assertSame('aesgcm', $this->attemptLines()[0]['content_encoding']);
    }

    public function test_each_outcome_is_named_from_what_the_push_service_answered(): void
    {
        $this->pushService([self::IPHONE => 201, self::PHONE => 403, self::LAPTOP => 410, self::WINDOWS => 429]);
        $user = $this->reviewer();
        $this->device($user, self::IPHONE);
        $this->device($user, self::PHONE);
        $this->device($user, self::LAPTOP);
        $this->device($user, self::WINDOWS);
        Queue::fake(DeliverDeferredPush::class);

        $user->notifyNow($this->alert());

        $byProvider = [];
        foreach ($this->attemptLines() as $line) {
            $byProvider[$line['provider']] = [$line['provider_status'], $line['outcome']];
        }
        $this->assertSame([
            'apple' => [201, 'accepted'],
            'fcm' => [403, 'rejected'],
            'mozilla' => [410, 'expired'],
            'wns' => [429, 'backoff'],
        ], $byProvider);
    }

    public function test_a_server_error_and_no_answer_at_all_are_both_backoff(): void
    {
        $this->assertSame('backoff', PushDeliveryTrace::outcome(new MessageSentReport(new Request('POST', self::PHONE), new Response(503), false)));
        $this->assertSame('backoff', PushDeliveryTrace::outcome(new MessageSentReport(new Request('POST', self::PHONE), null, false)));
        $this->assertSame('rejected', PushDeliveryTrace::outcome(new MessageSentReport(new Request('POST', self::PHONE), new Response(413), false)));
        $this->assertSame('expired', PushDeliveryTrace::outcome(new MessageSentReport(new Request('POST', self::PHONE), new Response(404), false)));
    }

    public function test_a_device_waiting_out_a_back_off_is_logged_as_not_attempted(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService();
        $user = $this->reviewer();
        $this->device($user, self::PHONE)->forceFill(['failure_count' => 1, 'backoff_until' => now()->addMinutes(3)])->save();

        $user->notifyNow($this->alert());

        $this->assertSame([], $service->delivered);
        $lines = $this->attemptLines();
        $this->assertCount(1, $lines);
        $this->assertFalse($lines[0]['attempted']);
        $this->assertNull($lines[0]['provider_status']);
        $this->assertSame('backoff', $lines[0]['outcome']);
        $this->assertSame(1, $lines[0]['attempt']);
    }

    public function test_the_test_button_notification_is_traced_too_with_no_category(): void
    {
        $this->pushService();
        $user = $this->member();
        $this->device($user, self::LAPTOP);

        $user->notifyNow(new TestPushNotification);

        $line = $this->attemptLines()[0];
        $this->assertSame(TestPushNotification::class, $line['notification_class']);
        $this->assertNull($line['category_key']);
        $this->assertSame('mozilla', $line['provider']);
        $this->assertSame('accepted', $line['outcome']);
    }

    public function test_the_correlation_id_follows_an_alert_from_its_fan_out_into_the_deferred_retry(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $service = $this->pushService([self::PHONE => 503]);
        $user = $this->reviewer();
        $this->device($user, self::PHONE);

        $fanOut = new FanOutNotification('station_requests', $this->alert(), [$user->id]);
        $fanOut->handle(app(NotificationDispatchService::class));

        $first = $this->attemptLines();
        $this->assertCount(1, $first);
        $this->assertSame($fanOut->correlationId, $first[0]['correlation_id'], 'the fan-out job\'s id is the id on the first attempt');
        $this->assertSame('backoff', $first[0]['outcome']);

        Queue::assertPushed(DeliverDeferredPush::class, fn (DeliverDeferredPush $job): bool => $job->correlationId === $fanOut->correlationId);

        // The push service recovers and the deferred job runs after the back-off.
        $service->statusByEndpoint = [];
        Carbon::setTestNow('2026-10-30 12:16:00');
        Queue::pushed(DeliverDeferredPush::class)->first()->handle();

        $lines = $this->attemptLines();
        $this->assertCount(2, $lines);
        $this->assertSame($fanOut->correlationId, $lines[1]['correlation_id']);
        $this->assertSame(2, $lines[1]['attempt'], 'the retry is the second send opportunity');
        $this->assertSame('accepted', $lines[1]['outcome']);
        $this->assertSame($lines[0]['notification_id'], $lines[1]['notification_id'], 'the retry is the same notification');
    }

    public function test_a_deferred_job_still_in_back_off_logs_the_wait_under_the_same_correlation_id(): void
    {
        Queue::fake(DeliverDeferredPush::class);
        $this->pushService();
        $user = $this->reviewer();
        $this->device($user, self::PHONE)->forceFill(['failure_count' => 1, 'backoff_until' => now()->addMinutes(3)])->save();

        (new FanOutNotification('station_requests', $this->alert(), [$user->id]))->handle(app(NotificationDispatchService::class));
        $original = Queue::pushed(DeliverDeferredPush::class)->first();
        $id = $this->attemptLines()[0]['correlation_id'];

        $original->handle();

        $lines = $this->attemptLines();
        $this->assertCount(2, $lines);
        $this->assertSame($id, $lines[1]['correlation_id']);
        $this->assertFalse($lines[1]['attempted']);
        $this->assertSame(2, $lines[1]['attempt']);
        Queue::assertPushed(DeliverDeferredPush::class, fn (DeliverDeferredPush $job): bool => $job->attempt === 2 && $job->correlationId === $id);
    }

    public function test_each_fan_out_gets_its_own_correlation_id(): void
    {
        $this->assertNotSame(
            (new FanOutNotification('station_requests', $this->alert(1)))->correlationId,
            (new FanOutNotification('station_requests', $this->alert(2)))->correlationId,
        );
    }

    public function test_a_notification_that_cannot_carry_an_id_still_gets_one_per_send(): void
    {
        $this->pushService();
        $user = $this->member();
        $this->device($user, self::PHONE);
        $plain = new class extends \Illuminate\Notifications\Notification
        {
            public function via($notifiable): array
            {
                return [WebPushChannel::class];
            }

            public function toWebPush($notifiable, $notification): WebPushMessage
            {
                return (new WebPushMessage)->title('Alert')->body('Body')->data(['url' => '/']);
            }
        };

        $user->notifyNow($plain);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->attemptLines()[0]['correlation_id']);
    }

    public function test_nothing_that_could_reach_a_device_or_reveal_a_message_is_ever_logged(): void
    {
        $this->pushService([self::IPHONE => 201, self::PHONE => 403, self::LAPTOP => 410, self::WINDOWS => 503]);
        $user = $this->reviewer();
        foreach ([self::IPHONE, self::PHONE, self::LAPTOP, self::WINDOWS] as $endpoint) {
            $this->device($user, $endpoint);
        }
        Queue::fake(DeliverDeferredPush::class);

        $user->notifyNow($this->alert());
        $user->notifyNow(new TestPushNotification);

        $logged = $this->everythingLogged();
        $this->assertStringContainsString(PushDeliveryTrace::LOG_MESSAGE, $logged);
        foreach (['SECRET-IPHONE-TOKEN', 'SECRET-FCM-TOKEN', 'SECRET-MOZILLA-TOKEN', 'SECRET-WNS-TOKEN', 'web.push.apple.com', 'googleapis.com', 'mozilla.com', 'notify.windows.com',
            self::PUBLIC_KEY, self::AUTH, 'Private message body', 'Request 1', 'Peter private iPhone label'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $logged, "the log must not contain [{$forbidden}]");
        }
    }

    public function test_a_refusal_is_logged_without_the_push_services_error_text_which_quotes_the_endpoint(): void
    {
        $user = $this->member();
        $device = $this->device($user, self::IPHONE);
        $report = new MessageSentReport(
            new Request('POST', self::IPHONE),
            new Response(403, [], '{"reason":"BadJwtToken"}'),
            false,
            'Client error: `POST '.self::IPHONE.'` resulted in a `403 Forbidden` response: {"reason":"BadJwtToken"}',
        );

        app(PushDeliveryOutcomeRecorder::class)->handle(new NotificationFailed($report, $device, new WebPushMessage));

        $logged = $this->everythingLogged();
        $this->assertStringContainsString('BadJwtToken', $logged, 'Apple\'s own short reason code is useful and safe');
        $this->assertStringNotContainsString('SECRET-IPHONE-TOKEN', $logged);
        $this->assertStringNotContainsString('web.push.apple.com', $logged);
    }

    public function test_a_provider_reason_that_is_not_a_plain_code_is_dropped(): void
    {
        $report = new MessageSentReport(new Request('POST', self::IPHONE), new Response(400, [], '{"reason":"see https://x.test/?a=b"}'), false);

        $this->assertNull(PushDeliveryTrace::providerReason($report));
    }
}
