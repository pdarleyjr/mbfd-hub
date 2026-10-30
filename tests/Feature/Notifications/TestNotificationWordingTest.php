<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\Feature\Notifications\Concerns\FakeWebPush;
use Tests\TestCase;

/**
 * "Send a test" reports what each push service SAID, never that the phone showed it.
 * The older keys (label, accepted, failure) stay for pages still running older JavaScript.
 */
final class TestNotificationWordingTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const IPHONE = 'https://web.push.apple.com/iphone-token';

    private const ANDROID = 'https://fcm.googleapis.com/fcm/send/android';

    private const WINDOWS = 'https://wns2-by3p.notify.windows.com/w/?token=abc';

    private const FIREFOX = 'https://updates.push.services.mozilla.com/wpush/v2/firefox';

    private function pushService(array $statusByEndpoint = []): FakeWebPush
    {
        $service = new FakeWebPush($statusByEndpoint);
        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): FakeWebPush => $service);

        return $service;
    }

    private function device(User $user, string $endpoint, string $label): void
    {
        $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'device_label' => $label,
        ]);
    }

    public function test_each_device_says_which_push_service_accepted_rejected_or_needs_setup(): void
    {
        $this->pushService([self::ANDROID => 403, self::WINDOWS => 410, self::FIREFOX => 503]);
        $me = $this->member();
        $this->device($me, self::IPHONE, 'iPhone');
        $this->device($me, self::ANDROID, 'Android phone');
        $this->device($me, self::WINDOWS, 'Windows laptop');
        $this->device($me, self::FIREFOX, 'Firefox');

        $response = $this->actingAs($me)->postJson('/api/push/test')->assertOk()->assertJsonPath('success', true);
        $devices = collect($response->json('devices'))->keyBy('label');

        $this->assertCount(4, $devices);
        foreach ($devices as $device) {
            $this->assertArrayHasKey('accepted', $device, 'older pages still read accepted');
            $this->assertArrayHasKey('failure', $device, 'older pages still read failure');
            $this->assertDoesNotMatchRegularExpression('/deliver|sent to your phone/i', $device['summary']);
        }

        $this->assertSame('accepted', $devices['iPhone']['outcome']);
        $this->assertTrue($devices['iPhone']['accepted']);
        $this->assertSame('Apple', $devices['iPhone']['service']);
        $this->assertStringStartsWith("Accepted by Apple's push service. Check this device", $devices['iPhone']['summary']);

        $this->assertSame('rejected', $devices['Android phone']['outcome']);
        $this->assertSame('Google', $devices['Android phone']['service']);
        $this->assertStringStartsWith("Rejected by Google's push service: the Hub's sender key was not accepted (code 403)", $devices['Android phone']['summary']);

        $this->assertSame('expired', $devices['Windows laptop']['outcome']);
        $this->assertSame('Microsoft', $devices['Windows laptop']['service']);
        $this->assertFalse($devices['Windows laptop']['accepted']);
        $this->assertStringContainsString('needs to be set up again', $devices['Windows laptop']['summary']);

        $this->assertSame('retrying', $devices['Firefox']['outcome']);
        $this->assertSame('Mozilla', $devices['Firefox']['service']);
        $this->assertStringContainsString('The Hub will try again on its own', $devices['Firefox']['summary']);

        $this->assertDoesNotMatchRegularExpression('/deliver/i', (string) $response->json('message'));
    }

    public function test_a_device_the_server_does_not_know_is_told_to_set_up_again(): void
    {
        $this->pushService();
        $me = $this->member();
        $this->device($me, self::IPHONE, 'iPhone');

        $this->actingAs($me)->postJson('/api/push/test', ['endpoint' => 'https://web.push.apple.com/gone'])
            ->assertStatus(400)
            ->assertJsonPath('outcome', 'needs_setup')
            ->assertJsonStructure(['error']);
    }
}
