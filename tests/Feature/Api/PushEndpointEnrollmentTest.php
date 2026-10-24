<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enrollment accepts the official browser push services out of the box (production had an
 * empty host list, which rejected every device), keeps every SSRF guard, and moves a shared
 * browser's endpoint between members only when the same subscription keys prove possession.
 */
final class PushEndpointEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function officialPushServices(): array
    {
        return [
            'Chrome / FCM' => ['https://fcm.googleapis.com/fcm/send/abc123'],
            'Firefox / Mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/abc123'],
            'Safari and iPhone / Apple' => ['https://web.push.apple.com/QWxhZGRpbjpvcGVuIHNlc2FtZQ'],
            'Edge / Windows regional host' => ['https://wns2-ch1p.notify.windows.com/w/?token=abc123'],
            'Edge / Windows another region' => ['https://db5p.notify.windows.com/w/?token=abc123'],
            'Edge / Windows nested label' => ['https://x.y.notify.windows.com/w/?token=abc123'],
            'explicit port 443' => ['https://fcm.googleapis.com:443/fcm/send/abc123'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function refusedEndpoints(): array
    {
        return [
            'WNS suffix lookalike' => ['https://notify.windows.com.evil.test/w/?token=abc'],
            'WNS prefix lookalike' => ['https://evilnotify.windows.com/w/?token=abc'],
            'WNS bare base is not a regional host' => ['https://notify.windows.com/w/?token=abc'],
            'FCM suffix lookalike' => ['https://fcm.googleapis.com.evil.test/fcm/send/abc'],
            'FCM prefix lookalike' => ['https://evilfcm.googleapis.com/fcm/send/abc'],
            'other googleapis host' => ['https://storage.googleapis.com/bucket/object'],
            'plain http' => ['http://wns2-ch1p.notify.windows.com/w/?token=abc'],
            'non-443 port' => ['https://wns2-ch1p.notify.windows.com:8443/w/?token=abc'],
            'embedded credentials' => ['https://user:pass@wns2-ch1p.notify.windows.com/w/?token=abc'],
            'ip address' => ['https://203.0.113.9/fcm/send/abc'],
            'localhost' => ['https://localhost/fcm/send/abc'],
            'unrelated host' => ['https://push.example.test/send/abc'],
        ];
    }

    private function member(): User
    {
        return User::factory()->create(['account_status' => AccountStatus::Active]);
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private function payload(string $endpoint, string $p256dhFill = "\x11", string $authFill = "\x22"): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => $this->base64Url("\x04".str_repeat($p256dhFill, 64)),
                'auth' => $this->base64Url(str_repeat($authFill, 16)),
            ],
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @dataProvider officialPushServices */
    public function test_the_default_configuration_accepts_each_official_push_service(string $endpoint): void
    {
        $this->assertContains('fcm.googleapis.com', config('webpush.allowed_endpoint_hosts'), 'a blank environment value uses the defaults');

        $this->actingAs($this->member())
            ->postJson('/api/push-subscriptions', $this->payload($endpoint))
            ->assertOk()
            ->assertJsonPath('subscriptionCount', 1);
    }

    /** @dataProvider refusedEndpoints */
    public function test_the_default_configuration_refuses_lookalikes_and_unsafe_endpoints(string $endpoint): void
    {
        $this->actingAs($this->member())
            ->postJson('/api/push-subscriptions', $this->payload($endpoint))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseCount(config('webpush.table_name'), 0);
    }

    public function test_an_explicit_host_list_replaces_the_defaults(): void
    {
        config(['webpush.allowed_endpoint_hosts' => ['push.example.test']]);
        $member = $this->member();

        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload('https://fcm.googleapis.com/fcm/send/abc'))
            ->assertUnprocessable();
        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload('https://push.example.test/send/abc'))
            ->assertOk();
    }

    /** @dataProvider tooBroadWildcards */
    public function test_a_wildcard_must_name_at_least_a_three_label_base(string $entry): void
    {
        config(['webpush.allowed_endpoint_hosts' => [$entry]]);

        $this->actingAs($this->member())
            ->postJson('/api/push-subscriptions', $this->payload('https://fcm.googleapis.com/fcm/send/abc'))
            ->assertUnprocessable();
    }

    /** @return array<string, array{string}> */
    public static function tooBroadWildcards(): array
    {
        return [
            'top-level domain' => ['*.com'],
            'public suffix' => ['*.co.uk'],
            'shared cloud domain' => ['*.googleapis.com'],
            'bare star' => ['*'],
            'star dot' => ['*.'],
            'double wildcard' => ['*.*.notify.windows.com'],
        ];
    }

    public function test_the_same_browser_moves_to_the_member_who_proves_the_same_keys(): void
    {
        $first = $this->member();
        $second = $this->member();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/shared-station-browser';

        $this->actingAs($first)->postJson('/api/push-subscriptions', $this->payload($endpoint))->assertOk();
        $this->actingAs($second)->postJson('/api/push-subscriptions', $this->payload($endpoint))
            ->assertOk()
            ->assertJsonPath('subscriptionCount', 1);

        $this->assertDatabaseCount(config('webpush.table_name'), 1);
        $this->assertSame(0, $first->pushSubscriptions()->count(), 'the previous member no longer gets alerts on this browser');
        $this->assertSame(1, $second->pushSubscriptions()->count());
    }

    public function test_a_claim_with_different_keys_is_still_refused(): void
    {
        $owner = $this->member();
        $claimant = $this->member();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/not-yours';
        $this->actingAs($owner)->postJson('/api/push-subscriptions', $this->payload($endpoint))->assertOk();

        $this->actingAs($claimant)->postJson('/api/push-subscriptions', $this->payload($endpoint, "\x33", "\x44"))->assertConflict();
        $this->actingAs($claimant)->postJson('/api/push-subscriptions', $this->payload($endpoint, "\x11", "\x44"))->assertConflict();
        $this->actingAs($claimant)->postJson('/api/push-subscriptions', $this->payload($endpoint, "\x33", "\x22"))->assertConflict();

        $this->assertSame(1, $owner->pushSubscriptions()->count());
        $this->assertSame(0, $claimant->pushSubscriptions()->count());
    }

    public function test_enrolling_records_a_recognizable_device_name_and_clears_old_failures(): void
    {
        $member = $this->member();
        $agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1';
        $endpoint = 'https://web.push.apple.com/iphone-token';

        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload($endpoint), ['User-Agent' => $agent])->assertOk();
        $device = $member->pushSubscriptions()->sole();
        $this->assertSame('Safari on iPhone', $device->device_label);
        $this->assertSame($agent, $device->user_agent);

        $device->forceFill(['failure_count' => 4, 'last_failure_reason' => 'rate_limited (429)', 'backoff_until' => now()->addHours(2)])->save();
        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload($endpoint), ['User-Agent' => $agent])->assertOk();

        $device = $member->pushSubscriptions()->sole();
        $this->assertSame(0, $device->failure_count);
        $this->assertNull($device->backoff_until);
        $this->assertNull($device->last_failure_reason);
    }

    public function test_the_standard_content_encoding_is_stored_unless_the_browser_only_supports_the_old_one(): void
    {
        $member = $this->member();

        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload('https://web.push.apple.com/default-encoding'))->assertOk();
        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload('https://fcm.googleapis.com/fcm/send/old-browser') + ['content_encoding' => 'aesgcm'])->assertOk();
        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload('https://fcm.googleapis.com/fcm/send/bad') + ['content_encoding' => 'rot13'])->assertUnprocessable();

        $this->assertSame('aes128gcm', $member->pushSubscriptions()->where('endpoint', 'like', '%apple%')->sole()->content_encoding);
        $this->assertSame('aesgcm', $member->pushSubscriptions()->where('endpoint', 'like', '%old-browser')->sole()->content_encoding);
    }

    public function test_a_label_hint_is_cleaned_and_limited(): void
    {
        $member = $this->member();
        $payload = $this->payload('https://fcm.googleapis.com/fcm/send/labelled') + ['label' => "<b>Station 3</b>\x07 kitchen tablet"];

        $this->actingAs($member)->postJson('/api/push-subscriptions', $payload)->assertOk();

        $this->assertSame('Station 3 kitchen tablet', $member->pushSubscriptions()->sole()->device_label);

        $this->actingAs($member)->postJson('/api/push-subscriptions', $this->payload('https://fcm.googleapis.com/fcm/send/toolong') + ['label' => str_repeat('x', 81)])
            ->assertUnprocessable();
    }

    public function test_removing_a_device_only_removes_the_members_own(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/mine';
        $this->actingAs($owner)->postJson('/api/push-subscriptions', $this->payload($endpoint))->assertOk();

        $this->actingAs($other)->deleteJson('/api/push-subscriptions', ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(1, $owner->pushSubscriptions()->count(), 'another member cannot delete my device');

        $this->actingAs($owner)->deleteJson('/api/push-subscriptions', ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(0, $owner->pushSubscriptions()->count());
    }
}
