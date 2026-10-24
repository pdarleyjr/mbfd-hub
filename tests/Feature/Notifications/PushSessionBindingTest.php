<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\SessionRegistry;
use App\Services\Notifications\PushSessionBinding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A push device is tied to the sign-in it was enrolled from. Signing out (or revoking every session) removes
 * the devices of the ended sign-ins only: a shared station browser stops showing the previous member's
 * alerts, the member's other devices keep working, and a session that merely times out deletes nothing.
 */
final class PushSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_PC = 'https://fcm.googleapis.com/fcm/send/shared-pc';

    private const PHONE = 'https://web.push.apple.com/phone-token';

    private const LAPTOP = 'https://updates.push.services.mozilla.com/wpush/v2/laptop';

    /** A member with a registered canonical session (the test case registers it, exactly as a login would). */
    private function signedInMember(string $employeeId = '10010'): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Session Binding Test',
            'rank' => 'Firefighter',
            'password' => 'legacy-secret',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('correct-password'),
        ]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private function payload(string $endpoint): array
    {
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $b64("\x04".str_repeat("\x11", 64)), 'auth' => $b64(str_repeat("\x22", 16))],
        ];
    }

    private function sameOrigin(): array
    {
        return ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'];
    }

    private function unbound(User $user, string $endpoint, ?string $binding = null): HubPushSubscription
    {
        /** @var HubPushSubscription $device */
        $device = $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'session_binding' => $binding,
        ]);

        return $device;
    }

    public function test_enrolling_records_a_hash_of_the_canonical_session_never_the_raw_id(): void
    {
        $user = $this->signedInMember();
        $registered = AuthenticationSession::query()->sole();

        $this->postJson('/api/push-subscriptions', $this->payload(self::SHARED_PC), $this->sameOrigin())->assertOk();

        $device = $user->pushSubscriptions()->sole();
        $this->assertSame(hash('sha256', (string) $registered->id), $device->session_binding);
        $this->assertNotSame((string) $registered->id, $device->session_binding);
    }

    public function test_a_request_without_a_canonical_session_has_no_binding(): void
    {
        $this->assertNull(PushSessionBinding::forRequest(Request::create('/api/push-subscriptions', 'POST')));

        $request = Request::create('/api/push-subscriptions', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $this->assertNull(PushSessionBinding::forRequest($request));
    }

    public function test_signing_out_of_a_shared_pc_removes_only_the_devices_enrolled_from_that_sign_in(): void
    {
        $user = $this->signedInMember();
        $this->postJson('/api/push-subscriptions', $this->payload(self::SHARED_PC), $this->sameOrigin())->assertOk();
        $otherSession = $this->unbound($user, self::PHONE, hash('sha256', 'another-sign-in-of-the-same-member'));
        $legacy = $this->unbound($user, self::LAPTOP);
        $bystander = User::factory()->create(['account_status' => AccountStatus::Active]);
        $bystanderDevice = $this->unbound($bystander, 'https://updates.push.services.mozilla.com/wpush/v2/bystander', hash('sha256', 'bystander'));

        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame(
            [self::LAPTOP, self::PHONE],
            $user->pushSubscriptions()->orderBy('endpoint')->pluck('endpoint')->all(),
            'the shared PC device is gone; the member\'s other devices stay',
        );
        $this->assertNotNull($otherSession->fresh());
        $this->assertNotNull($legacy->fresh());
        $this->assertNotNull($bystanderDevice->fresh(), 'another member is untouched');
    }

    public function test_the_next_member_on_the_shared_pc_does_not_inherit_the_previous_members_alerts(): void
    {
        $first = $this->signedInMember('20001');
        $this->postJson('/api/push-subscriptions', $this->payload(self::SHARED_PC), $this->sameOrigin())->assertOk();
        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame(0, HubPushSubscription::query()->where('endpoint', self::SHARED_PC)->count());
    }

    public function test_a_session_that_only_expires_keeps_its_devices(): void
    {
        $user = $this->signedInMember();
        $this->postJson('/api/push-subscriptions', $this->payload(self::SHARED_PC), $this->sameOrigin())->assertOk();
        $registered = AuthenticationSession::query()->sole();

        app(SessionRegistry::class)->revoke($user, (string) $registered->id, 'idle timeout', CarbonImmutable::now());

        $this->assertNotNull($registered->fresh()->revoked_at);
        $this->assertSame(1, $user->pushSubscriptions()->count(), 'expiry alone never deletes a device');
    }

    public function test_revoking_every_session_removes_the_devices_bound_to_any_of_them_and_nothing_else(): void
    {
        $user = $this->signedInMember();
        $first = AuthenticationSession::query()->sole();
        $second = AuthenticationSession::factory()->create(['user_id' => $user->id]);
        $this->unbound($user, self::SHARED_PC, hash('sha256', (string) $first->id));
        $this->unbound($user, self::PHONE, hash('sha256', (string) $second->id));
        $kept = $this->unbound($user, self::LAPTOP);
        $bystander = User::factory()->create(['account_status' => AccountStatus::Active]);
        $bystanderDevice = $this->unbound($bystander, 'https://updates.push.services.mozilla.com/wpush/v2/bystander', hash('sha256', (string) $first->id));

        app(AccountSecurityService::class)->revokeAll($user, 'security reset', CarbonImmutable::now());

        $this->assertSame([self::LAPTOP], $user->pushSubscriptions()->pluck('endpoint')->all());
        $this->assertNotNull($kept->fresh());
        $this->assertNotNull($bystanderDevice->fresh(), 'a binding only matches the owner of the ended sessions');
    }
}
