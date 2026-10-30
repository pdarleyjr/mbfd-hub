<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\HubPushSubscription;
use App\Models\User;
use App\Notifications\TestPushNotification;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/**
 * Root cause of "Send test notification never pops up on the iPhone" (2026-10-30): every device
 * enrolled before the Hub stored an encoding had content_encoding NULL, minishlink/web-push turned
 * that into the legacy "aesgcm" draft, Apple answered 201 and the iPhone could not decrypt it.
 * These tests pin the encoding every sender actually hands to the push library.
 */
final class PushContentEncodingTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const IPHONE = 'https://web.push.apple.com/QJ-legacy-iphone';

    private const WINDOWS = 'https://wns2-ch1p.notify.windows.com/w/?token=legacy';

    /** @return object{encodings: array<string, string|null>} */
    private function recordingPushService(): object
    {
        $service = new class([]) extends WebPush
        {
            /** @var array<string, string|null> */
            public array $encodings = [];

            /** @var list<string> */
            private array $queued = [];

            public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
            {
                $this->encodings[$subscription->getEndpoint()] = $subscription->getContentEncoding();
                $this->queued[] = $subscription->getEndpoint();
            }

            public function flush(?int $batchSize = null): \Generator
            {
                foreach ($this->queued as $endpoint) {
                    yield new MessageSentReport(new Request('POST', $endpoint), new Response(201), true, 'OK');
                }
                $this->queued = [];
            }
        };
        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => $service);

        return $service;
    }

    /** A row exactly as the January to April enrollments left it: no encoding stored. */
    private function legacyDevice(User $user, string $endpoint): HubPushSubscription
    {
        $id = DB::table(config('webpush.table_name'))->insertGetId([
            'subscribable_type' => $user->getMorphClass(),
            'subscribable_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'content_encoding' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return HubPushSubscription::query()->findOrFail($id);
    }

    public function test_a_device_with_no_stored_encoding_is_sent_rfc8291_aes128gcm(): void
    {
        $user = $this->member();
        $device = $this->legacyDevice($user, self::IPHONE);

        $this->assertNull($device->getRawOriginal('content_encoding'));
        $this->assertSame('aes128gcm', $device->content_encoding);
    }

    public function test_the_test_button_sends_aes128gcm_to_legacy_iphone_and_windows_devices(): void
    {
        $service = $this->recordingPushService();
        $user = $this->member();
        $this->legacyDevice($user, self::IPHONE);
        $this->legacyDevice($user, self::WINDOWS);

        $user->notifyNow(new TestPushNotification);

        $this->assertSame([self::IPHONE => 'aes128gcm', self::WINDOWS => 'aes128gcm'], $service->encodings);
    }

    public function test_ordinary_alerts_through_the_hub_channel_send_aes128gcm_to_legacy_devices(): void
    {
        $service = $this->recordingPushService();
        $user = $this->member();
        $this->legacyDevice($user, self::IPHONE);

        $notification = new class extends \Illuminate\Notifications\Notification
        {
            public function via($notifiable): array
            {
                return [WebPushChannel::class];
            }

            public function toWebPush($notifiable, $notification): \NotificationChannels\WebPush\WebPushMessage
            {
                return (new \NotificationChannels\WebPush\WebPushMessage)->title('Alert')->body('Body')->data(['url' => '/']);
            }
        };
        $user->notifyNow($notification);

        $this->assertSame([self::IPHONE => 'aes128gcm'], $service->encodings);
    }

    public function test_an_encoding_the_browser_reported_is_kept(): void
    {
        $service = $this->recordingPushService();
        $user = $this->member();
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/old-chrome',
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'content_encoding' => 'aesgcm',
        ]);

        $user->notifyNow(new TestPushNotification);

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/old-chrome' => 'aesgcm'], $service->encodings);
    }

    public function test_the_backfill_migration_records_aes128gcm_for_rows_without_an_encoding_only(): void
    {
        $user = $this->member();
        $legacy = $this->legacyDevice($user, self::IPHONE);
        $kept = $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/kept',
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'content_encoding' => 'aesgcm',
        ]);
        $before = DB::table(config('webpush.table_name'))->orderBy('id')->get(['id', 'endpoint', 'public_key', 'auth_token', 'updated_at']);

        (require database_path('migrations/2026_10_30_200000_backfill_push_subscription_content_encoding.php'))->up();

        $this->assertSame('aes128gcm', $legacy->fresh()->getRawOriginal('content_encoding'));
        $this->assertSame('aesgcm', $kept->fresh()->getRawOriginal('content_encoding'));
        // Endpoints, keys and timestamps are untouched: no device has to enroll again.
        $this->assertEquals($before, DB::table(config('webpush.table_name'))->orderBy('id')->get(['id', 'endpoint', 'public_key', 'auth_token', 'updated_at']));
    }
}
