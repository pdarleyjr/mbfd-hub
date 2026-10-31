<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\HubPushSubscription;
use App\Models\User;
use App\Services\Notifications\PushDeviceRemovals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PushDeviceStateTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'https://web.push.apple.com/phone-token';

    private const LAPTOP = 'https://fcm.googleapis.com/fcm/send/laptop';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function device(User $user, string $endpoint, ?string $label = 'iPhone'): HubPushSubscription
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

    /** @return array<string, mixed> */
    private function enrollment(): array
    {
        return [
            'endpoint' => self::PHONE,
            'keys' => [
                'p256dh' => rtrim(strtr(base64_encode("\x04".str_repeat('A', 64)), '+/', '-_'), '='),
                'auth' => rtrim(strtr(base64_encode(str_repeat('B', 16)), '+/', '-_'), '='),
            ],
        ];
    }

    private function state(string $endpoint): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/push/device-state?hash='.hash('sha256', $endpoint));
    }

    public function test_it_needs_a_member_and_a_real_hash_and_never_accepts_the_endpoint_itself(): void
    {
        $this->state(self::PHONE)->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/push/device-state?hash='.urlencode(self::PHONE))->assertUnprocessable();
    }

    public function test_it_knows_only_this_members_device(): void
    {
        $member = User::factory()->create();
        $this->device($member, self::PHONE);
        $this->device(User::factory()->create(), self::LAPTOP);

        $this->actingAs($member);

        $this->state(self::PHONE)->assertOk()->assertExactJson(['known' => true, 'refresh' => false, 'removed' => false])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->state(self::LAPTOP)->assertOk()->assertExactJson(['known' => false, 'refresh' => false, 'removed' => false]);
    }

    public function test_an_old_row_with_no_device_name_asks_for_one_refresh(): void
    {
        $member = User::factory()->create();
        $this->device($member, self::PHONE, null);

        $this->actingAs($member)->state(self::PHONE)->assertJson(['known' => true, 'refresh' => true]);
    }

    public function test_a_repair_saves_an_unknown_device_with_the_standard_encoding_and_its_name(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->enrollment() + ['sync' => true])->assertOk();

        $device = $member->pushSubscriptions()->sole();
        $this->assertSame('aes128gcm', $device->getRawOriginal('content_encoding'));
        $this->assertNotNull($device->device_label);
    }

    public function test_a_repair_cannot_undo_a_remove_but_turning_alerts_on_again_can(): void
    {
        $member = User::factory()->create();
        app(PushDeviceRemovals::class)->remember(self::PHONE);
        $this->actingAs($member);

        $this->state(self::PHONE)->assertJson(['known' => false, 'removed' => true]);
        $this->postJson('/api/push-subscriptions', $this->enrollment() + ['sync' => true])
            ->assertStatus(409)->assertJson(['removed' => true]);
        $this->assertSame(0, $member->pushSubscriptions()->count());

        $this->postJson('/api/push-subscriptions', $this->enrollment())->assertOk();
        $this->assertSame(1, $member->pushSubscriptions()->count());
        $this->state(self::PHONE)->assertJson(['known' => true, 'removed' => false]);
    }

    public function test_a_shared_device_can_still_move_to_the_member_now_signed_in(): void
    {
        $previous = User::factory()->create();
        $this->actingAs($previous)->postJson('/api/push-subscriptions', $this->enrollment())->assertOk();

        $next = User::factory()->create();
        $this->actingAs($next)->postJson('/api/push-subscriptions', $this->enrollment() + ['sync' => true])->assertOk();

        $this->assertSame(0, $previous->pushSubscriptions()->count());
        $this->assertSame(1, $next->pushSubscriptions()->count());
    }

    public function test_signed_in_pages_carry_the_marker_and_signed_out_pages_do_not(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertDontSee('mbfd-alerts-feed', false);
    }
}
