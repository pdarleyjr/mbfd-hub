<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\HubPushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\Feature\Notifications\Concerns\FakeWebPush;
use Tests\TestCase;

/** The "send a test" result keeps its existing keys and adds provider, status and a fixed note. */
final class PushDeliveryHealthTestButtonTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const IPHONE = 'https://web.push.apple.com/iphone-token';

    private const PHONE = 'https://fcm.googleapis.com/fcm/send/phone-token';

    private const LAPTOP = 'https://updates.push.services.mozilla.com/wpush/v2/laptop-token';

    private const WINDOWS = 'https://wns2-ch1p.notify.windows.com/w/?token=windows-token';

    private const OTHER = 'https://web.push.apple.com/other-token';

    /** @param array<string, int> $statuses */
    private function pushService(array $statuses = []): FakeWebPush
    {
        $service = new FakeWebPush($statuses);
        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): FakeWebPush => $service);

        return $service;
    }

    private function device(User $user, string $endpoint, string $label): HubPushSubscription
    {
        /** @var HubPushSubscription $device */
        $device = $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'device_label' => $label,
        ]);

        return $device;
    }

    public function test_every_device_reports_provider_and_one_of_the_five_statuses_next_to_the_existing_keys(): void
    {
        $this->pushService([self::PHONE => 403, self::LAPTOP => 429, self::WINDOWS => 410]);
        $me = $this->member();
        $this->device($me, self::IPHONE, 'iPhone');
        $this->device($me, self::PHONE, 'Android');
        $this->device($me, self::LAPTOP, 'Linux laptop');
        $this->device($me, self::WINDOWS, 'Windows PC');

        $response = $this->actingAs($me)->postJson('/api/push/test')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Test notification sent.')
            ->assertJsonPath('note', 'Accepted means Apple/Google/Microsoft/Mozilla took the alert. Whether the phone displayed it is not observable by the server.')
            ->assertJsonCount(4, 'devices');

        $byLabel = collect($response->json('devices'))->keyBy('label');
        foreach ($byLabel as $device) {
            $this->assertEqualsCanonicalizing(['label', 'accepted', 'failure', 'provider', 'status'], array_keys($device));
        }

        $this->assertSame(['accepted' => true, 'failure' => null, 'provider' => 'apple', 'status' => 'Accepted by push service'],
            collect($byLabel['iPhone'])->except('label')->all());
        $this->assertSame(['accepted' => false, 'failure' => 'rejected (403)', 'provider' => 'fcm', 'status' => 'Rejected by push service'],
            collect($byLabel['Android'])->except('label')->all());
        $this->assertSame(['accepted' => false, 'failure' => 'rate_limited (429)', 'provider' => 'mozilla', 'status' => 'Waiting to retry'],
            collect($byLabel['Linux laptop'])->except('label')->all());
    }

    public function test_a_device_the_push_service_has_dropped_is_reported_not_silently_left_out(): void
    {
        $this->pushService([self::WINDOWS => 410]);
        $me = $this->member();
        $this->device($me, self::IPHONE, 'iPhone');
        $this->device($me, self::WINDOWS, 'Windows PC');

        $response = $this->actingAs($me)->postJson('/api/push/test')->assertOk()->assertJsonCount(2, 'devices');

        $windows = collect($response->json('devices'))->firstWhere('label', 'Windows PC');
        $this->assertFalse($windows['accepted']);
        $this->assertSame('wns', $windows['provider']);
        $this->assertSame('Device setup needed', $windows['status']);
        $this->assertSame(1, $me->pushSubscriptions()->count(), 'the device was removed as before');
    }

    public function test_targeting_one_device_reports_only_that_device(): void
    {
        $this->pushService();
        $me = $this->member();
        $this->device($me, self::IPHONE, 'iPhone');
        $this->device($me, self::PHONE, 'Android');

        $response = $this->actingAs($me)->postJson('/api/push/test', ['endpoint' => self::PHONE])->assertOk()->assertJsonCount(1, 'devices');

        $this->assertSame('fcm', $response->json('devices.0.provider'));
        $this->assertSame('Accepted by push service', $response->json('devices.0.status'));
    }

    public function test_the_result_never_contains_an_endpoint_or_says_delivered(): void
    {
        $this->pushService();
        $me = $this->member();
        $this->device($me, self::IPHONE, 'iPhone');

        $body = $this->actingAs($me)->postJson('/api/push/test')->assertOk()->getContent();

        $this->assertStringNotContainsString('iphone-token', $body);
        $this->assertStringNotContainsString('web.push.apple.com', $body);
        $this->assertStringNotContainsStringIgnoringCase('delivered', $body);
    }

    public function test_the_test_button_still_ignores_category_preferences_and_only_reaches_the_requesters_devices(): void
    {
        $service = $this->pushService();
        $me = $this->member();
        $other = $this->member();
        $this->device($me, self::IPHONE, 'iPhone');
        $this->device($other, self::OTHER, 'Not mine');

        $this->actingAs($me)->postJson('/api/push/test')->assertOk();

        $this->assertSame([self::IPHONE], $service->delivered);
    }
}
