<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\Employee;
use App\Models\User;
use App\Models\UserIdentityLink;
use App\Services\Identity\AuthentikIdentityProvider;
use App\Services\Identity\IdentityProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AuthentikIdentityProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'identity.mode' => 'hybrid',
            'identity.authentik.issuer' => 'https://auth.mbfdhub.test/application/o/mbfd-hub/',
            'identity.authentik.api_url' => 'https://auth.mbfdhub.test',
            'identity.authentik.api_token' => 'test-api-token',
        ]);
    }

    public function test_provisioning_uses_exact_employee_id_and_creates_only_an_immutable_subject_link(): void
    {
        [$employee, $user] = $this->identity();
        config(['identity.canary_user_ids' => [$user->getKey()]]);
        Http::fake([
            'https://auth.mbfdhub.test/api/v3/core/users/' => Http::response([
                'pk' => 44,
                'uuid' => '5c7363f4-94e3-4b37-bab0-6f9feea7e778',
            ], 201),
        ]);

        $link = app(IdentityProviderService::class)->provision($user);

        self::assertSame('5c7363f4-94e3-4b37-bab0-6f9feea7e778', $link->subject);
        self::assertSame('44', $link->provider_user_id);
        Http::assertSent(function (Request $request) use ($employee, $user): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://auth.mbfdhub.test/api/v3/core/users/'
                && $request['username'] === $employee->employee_id
                && $request['email'] === $employee->city_email
                && $request['attributes']['mbfd_employee_profile_id'] === $user->employee_profile_id;
        });
    }

    public function test_provider_never_searches_or_auto_links_after_a_remote_conflict(): void
    {
        [, $user] = $this->identity();
        config(['identity.canary_user_ids' => [$user->getKey()]]);
        Http::fake([
            'https://auth.mbfdhub.test/api/v3/core/users/' => Http::response(['username' => ['Already exists.']], 400),
        ]);

        try {
            app(IdentityProviderService::class)->provision($user);
            self::fail('A remote collision must stop for identity review.');
        } catch (\Illuminate\Http\Client\RequestException) {
            self::assertFalse(UserIdentityLink::query()->exists());
            Http::assertSentCount(1);
        }
    }

    public function test_recovery_url_must_remain_on_the_configured_identity_host(): void
    {
        [, $user] = $this->identity();
        $link = UserIdentityLink::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'authentik',
            'subject' => '5c7363f4-94e3-4b37-bab0-6f9feea7e778',
            'provider_user_id' => '44',
            'status' => 'active',
        ]);
        Http::fake([
            '*' => Http::response(['link' => 'https://attacker.invalid/recovery'], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        app(AuthentikIdentityProvider::class)->recoveryLink($user, $link);
    }

    public function test_security_inventory_is_bounded_and_mfa_reset_deletes_only_supported_devices(): void
    {
        [, $user] = $this->identity();
        $link = UserIdentityLink::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'authentik',
            'subject' => '5c7363f4-94e3-4b37-bab0-6f9feea7e778',
            'provider_user_id' => '44',
            'status' => 'active',
        ]);
        Http::fake([
            'https://auth.mbfdhub.test/api/v3/authenticators/admin/all/?user=44' => Http::response([
                ['pk' => 'totp-1', 'confirmed' => true, 'meta_model_name' => 'authentik_stages_authenticator_totp.totpdevice'],
                ['pk' => 'passkey-1', 'confirmed' => true, 'meta_model_name' => 'authentik_stages_authenticator_webauthn.webauthndevice'],
            ]),
            'https://auth.mbfdhub.test/api/v3/core/authenticated_sessions/?user=44&page_size=100' => Http::response([
                'results' => [['uuid' => '8c7363f4-94e3-4b37-bab0-6f9feea7e778']],
                'pagination' => ['next' => 0],
            ]),
            'https://auth.mbfdhub.test/api/v3/authenticators/admin/totp/totp-1/' => Http::response(status: 204),
            'https://auth.mbfdhub.test/api/v3/authenticators/admin/webauthn/passkey-1/' => Http::response(status: 204),
        ]);
        $provider = app(AuthentikIdentityProvider::class);

        $state = $provider->securityState($user, $link);
        $provider->resetMfa($user, $link);

        self::assertTrue($state['mfa_enrolled']);
        self::assertSame(1, $state['totp_count']);
        self::assertSame(1, $state['passkey_count']);
        self::assertSame(1, $state['active_session_count']);
        Http::assertSentCount(5);
    }

    /** @return array{Employee, User} */
    private function identity(): array
    {
        $employee = Employee::query()->create([
            'employee_id' => 'F00123',
            'name' => 'Pilot Member',
            'city_email' => 'pilot.member@miamibeachfl.gov',
            'roster_status' => 'active',
            'password' => 'test-only-password',
        ]);
        $user = User::factory()->create([
            'name' => $employee->name,
            'email' => $employee->city_email,
            'email_verified_at' => now(),
            'account_status' => 'active',
            'employee_profile_id' => $employee->getKey(),
            'employee_id' => $employee->employee_id,
        ]);

        return [$employee, $user];
    }
}
