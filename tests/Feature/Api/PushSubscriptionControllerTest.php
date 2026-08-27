<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/test-endpoint';

    protected function setUp(): void
    {
        parent::setUp();

        config(['webpush.allowed_endpoint_hosts' => ['fcm.googleapis.com']]);
    }

    public function test_an_authenticated_user_can_register_a_valid_subscription_for_a_configured_https_host(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/push-subscriptions', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('subscriptionCount', 1);

        $this->assertDatabaseHas(config('webpush.table_name'), [
            'endpoint' => self::ENDPOINT,
            'subscribable_id' => $user->id,
            'subscribable_type' => $user->getMorphClass(),
            'public_key' => $this->validP256dh(),
            'auth_token' => $this->validAuth(),
        ]);
    }

    public function test_a_subscription_endpoint_must_use_https(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/push-subscriptions', $this->validPayload('http://fcm.googleapis.com/fcm/send/test-endpoint'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseCount(config('webpush.table_name'), 0);
    }

    public function test_a_subscription_endpoint_must_match_an_exact_configured_host(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/push-subscriptions', $this->validPayload('https://fcm.googleapis.com.attacker.example/fcm/send/test-endpoint'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseCount(config('webpush.table_name'), 0);
    }

    public function test_a_subscription_endpoint_is_rejected_when_no_hosts_are_configured(): void
    {
        config(['webpush.allowed_endpoint_hosts' => []]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/push-subscriptions', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseCount(config('webpush.table_name'), 0);
    }

    public function test_unsafe_allowlist_entries_do_not_authorize_an_endpoint(): void
    {
        config(['webpush.allowed_endpoint_hosts' => [
            '*.googleapis.com',
            'https://fcm.googleapis.com',
            '127.0.0.1',
        ]]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/push-subscriptions', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseCount(config('webpush.table_name'), 0);
    }

    public function test_web_push_keys_must_have_valid_base64url_shapes(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['keys']['p256dh'] = 'not-a-valid-p256dh-key';
        $payload['keys']['auth'] = 'not-a-valid-auth-secret';

        $this->actingAs($user)
            ->postJson('/api/push-subscriptions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->assertDatabaseCount(config('webpush.table_name'), 0);
    }

    public function test_an_existing_subscription_cannot_be_reassigned_to_another_user(): void
    {
        $owner = User::factory()->create();
        $claimant = User::factory()->create();
        $owner->pushSubscriptions()->create([
            'endpoint' => self::ENDPOINT,
            'public_key' => $this->validP256dh(),
            'auth_token' => $this->validAuth(),
        ]);

        $payload = $this->validPayload();
        $payload['keys']['p256dh'] = $this->validP256dh("\x33");
        $payload['keys']['auth'] = $this->validAuth("\x44");

        $this->actingAs($claimant)
            ->postJson('/api/push-subscriptions', $payload)
            ->assertConflict()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount(config('webpush.table_name'), 1);
        $this->assertDatabaseHas(config('webpush.table_name'), [
            'endpoint' => self::ENDPOINT,
            'subscribable_id' => $owner->id,
            'subscribable_type' => $owner->getMorphClass(),
            'public_key' => $this->validP256dh(),
            'auth_token' => $this->validAuth(),
        ]);
    }

    public function test_subscription_management_is_limited_to_ten_requests_per_minute(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($user)
                ->postJson('/api/push-subscriptions', $this->validPayload())
                ->assertOk();
        }

        $this->actingAs($user)
            ->postJson('/api/push-subscriptions', $this->validPayload())
            ->assertTooManyRequests();
    }

    public function test_the_test_notification_route_uses_a_fake_notification_in_its_regression_test(): void
    {
        $user = User::factory()->create();
        $user->pushSubscriptions()->create([
            'endpoint' => self::ENDPOINT,
            'public_key' => $this->validP256dh(),
            'auth_token' => $this->validAuth(),
        ]);
        Notification::fake();

        $this->actingAs($user)
            ->postJson('/api/push/test')
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, TestPushNotification::class);
        Http::assertNothingSent();
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function validPayload(string $endpoint = self::ENDPOINT): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => $this->validP256dh(),
                'auth' => $this->validAuth(),
            ],
        ];
    }

    private function validP256dh(string $fill = "\x11"): string
    {
        return $this->base64UrlEncode("\x04".str_repeat($fill, 64));
    }

    private function validAuth(string $fill = "\x22"): string
    {
        return $this->base64UrlEncode(str_repeat($fill, 16));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
