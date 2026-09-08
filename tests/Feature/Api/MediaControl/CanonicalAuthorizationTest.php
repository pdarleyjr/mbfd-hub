<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MediaControl;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CityEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://media.mbfdhub.com/api/auth/hub/callback';

    private const SERVICE_TOKEN = 'media-control-service-token-fixture';

    private const STATE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.media_control.authorization.issuer', 'https://www.mbfdhub.com');
        config()->set('services.media_control.authorization.code_ttl_seconds', 60);
        config()->set('services.media_control.authorization.service_token', self::SERVICE_TOKEN);
        config()->set('services.media_control.authorization.clients.media-control.callbacks', [
            self::CALLBACK,
        ]);
    }

    #[DataProvider('authorizedRoleProvider')]
    public function test_explicit_media_entitlement_can_issue_and_exchange_a_canonical_code_across_existing_roles(string $role): void
    {
        $user = $this->linkedUser();
        $user->assignRole(Role::findOrCreate($role, 'web'));
        $user->givePermissionTo(Permission::findOrCreate('app.media_control.access', 'web'));
        $this->canonicalLogin($user);

        $location = $this->get($this->authorizeUrl())
            ->assertRedirect()
            ->headers->get('Location');
        self::assertIsString($location);
        $query = $this->redirectQuery($location);
        self::assertSame(self::STATE, $query['state'] ?? null);
        self::assertIsString($query['code'] ?? null);

        $this->exchange($query['code'])
            ->assertOk()
            ->assertJson([
                'issuer' => 'https://www.mbfdhub.com',
                'audience' => 'media-control',
                'subject' => 'hub-user:'.$user->id,
                'user_id' => $user->id,
                'display_name' => $user->display_name ?: $user->name,
                'role' => $role === 'super_admin' ? 'platform_admin' : 'user',
            ])
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('password_hash')
            ->assertJsonMissingPath('session')
            ->assertJsonMissingPath('email');
    }

    /** @return array<string, array{string}> */
    public static function authorizedRoleProvider(): array
    {
        return [
            'super admin' => ['super_admin'],
            'admin' => ['admin'],
            'logistics admin' => ['logistics_admin'],
            'training admin' => ['training_admin'],
        ];
    }

    public function test_authenticated_user_without_signage_entitlement_is_denied(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);

        $location = $this->get($this->authorizeUrl())
            ->assertRedirect()
            ->headers->get('Location');
        self::assertIsString($location);

        $query = $this->redirectQuery($location);
        self::assertSame('access_denied', $query['error'] ?? null);
        self::assertSame(self::STATE, $query['state'] ?? null);
        self::assertArrayNotHasKey('code', $query);
    }

    public function test_employee_link_is_not_required_for_media_control_authorization(): void
    {
        $user = $this->linkedUser();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('app.media_control.access', 'web'));
        $this->canonicalLogin($user);
        $user->forceFill(['employee_profile_id' => null])->save();
        $this->app['auth']->forgetGuards();

        $location = $this->get($this->authorizeUrl())
            ->assertRedirect()
            ->headers->get('Location');
        self::assertIsString($location);

        $query = $this->redirectQuery($location);
        self::assertIsString($query['code'] ?? null);
        $this->exchange($query['code'])->assertOk();
    }

    public function test_disabled_and_revoked_canonical_sessions_are_denied_before_issuance(): void
    {
        $disabled = $this->authorizedUser('MEDIA-DISABLED');
        $this->canonicalLogin($disabled);
        $disabled->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $this->federationLogin($this->authorizeUrl());

        $revoked = $this->authorizedUser('MEDIA-REVOKED');
        $this->canonicalLogin($revoked);
        AuthenticationSession::query()
            ->where('user_id', $revoked->id)
            ->update(['revoked_at' => now()]);
        $this->federationLogin($this->authorizeUrl());
    }

    public function test_callback_state_audience_expiry_tamper_and_replay_are_enforced(): void
    {
        $this->canonicalLogin($this->authorizedUser());

        foreach ([
            'https://evil.example/api/auth/hub/callback',
            '//media.mbfdhub.com/api/auth/hub/callback',
            'https://media.mbfdhub.com.evil.example/api/auth/hub/callback',
            self::CALLBACK.'/alternate',
        ] as $callback) {
            $this->withHeader('Accept', 'application/json')
                ->get($this->authorizeUrl(callback: $callback))
                ->assertUnprocessable();
        }

        $this->withHeader('Accept', 'application/json')
            ->get('/auth/media-control/authorize?client_id=media-control&redirect_uri='.rawurlencode(self::CALLBACK))
            ->assertUnprocessable();

        $code = $this->issuedCode();
        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertUnauthorized();

        $tamperCode = $this->issuedCode();
        $tampered = substr($tamperCode, 0, -1).($tamperCode[-1] === 'A' ? 'B' : 'A');
        $this->exchange($tampered)->assertUnauthorized();
        $this->exchange($tamperCode)->assertOk();

        $audienceCode = $this->issuedCode();
        $this->exchange($audienceCode, clientId: 'another-app')->assertUnauthorized();
        $this->exchange($audienceCode)->assertOk();

        $expiredCode = $this->issuedCode();
        $this->travel(61)->seconds();
        $this->exchange($expiredCode)->assertUnauthorized();
    }

    public function test_service_credential_is_required_and_fails_closed_when_unconfigured(): void
    {
        $this->canonicalLogin($this->authorizedUser());
        $code = $this->issuedCode();

        $this->postJson('/api/v2/media-control/auth/exchange', [
            'code' => $code,
            'client_id' => 'media-control',
            'redirect_uri' => self::CALLBACK,
        ])->assertUnauthorized();

        config()->set('services.media_control.authorization.service_token', null);
        $this->exchange($code)->assertServiceUnavailable();
    }

    public function test_code_cannot_outlive_security_version(): void
    {
        $securityVersionUser = $this->authorizedUser('MEDIA-SECURITY');
        $this->canonicalLogin($securityVersionUser);
        $securityVersionCode = $this->issuedCode();
        User::query()->whereKey($securityVersionUser->id)->increment('security_version');
        $this->exchange($securityVersionCode)->assertUnauthorized();
    }

    public function test_code_cannot_outlive_account_status(): void
    {
        $disabledUser = $this->authorizedUser('MEDIA-STATUS');
        $this->canonicalLogin($disabledUser);
        $disabledCode = $this->issuedCode();
        $disabledUser->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $this->exchange($disabledCode)->assertUnauthorized();
    }

    public function test_code_cannot_outlive_explicit_entitlement(): void
    {
        $roleUser = $this->authorizedUser('MEDIA-ROLE');
        $this->canonicalLogin($roleUser);
        $roleCode = $this->issuedCode();
        $roleUser->revokePermissionTo('app.media_control.access');
        $this->exchange($roleCode)->assertForbidden();
    }

    public function test_revalidation_binds_current_identity_and_direct_access_without_relying_on_old_roles(): void
    {
        $user = $this->authorizedUser();
        $this->canonicalLogin($user);
        $this->exchange($this->issuedCode())->assertOk()->assertJsonPath('security_version', (int) $user->security_version)
            ->assertJsonPath('member_id', $user->employee_profile_id);
        $data = ['hub_user_id' => $user->id, 'security_version' => (int) $user->security_version,
            'member_id' => $user->employee_profile_id, 'client_id' => 'media-control', 'media_control_security_version' => 0];
        $url = '/api/v2/media-control/auth/revalidate';
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, $data)->assertOk()
            ->assertJsonPath('subject', 'hub-user:'.$user->id)->assertJsonPath('role', 'user');
        $user->givePermissionTo(Permission::findOrCreate('app.media_control.admin', 'web'));
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, $data)->assertOk()->assertJsonPath('role', 'platform_admin');
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, [...$data, 'member_id' => null])->assertUnauthorized();
        $user->revokePermissionTo('app.media_control.access');
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, $data)->assertUnauthorized();
    }

    public function test_revalidation_preserves_unlinked_admin_but_rejects_password_setup_and_stale_version(): void
    {
        $user = $this->authorizedUser();
        $user->forceFill(['employee_profile_id' => null])->save();
        $data = ['hub_user_id' => $user->id, 'security_version' => (int) $user->security_version, 'member_id' => null, 'client_id' => 'media-control', 'media_control_security_version' => 0];
        $url = '/api/v2/media-control/auth/revalidate';
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, $data)->assertOk()->assertJsonPath('member_id', null);
        $user->forceFill(['must_change_password' => true])->save();
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, $data)->assertUnauthorized();
        $user->forceFill(['must_change_password' => false, 'security_version' => $user->security_version + 1])->save();
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, $data)->assertUnauthorized();
        $this->withToken('incorrect')->postJson($url, $data)->assertUnauthorized();
    }

    public function test_cloud_gate_requires_its_own_grant_and_approved_mapping_not_media_admin(): void
    {
        $user = $this->authorizedUser();
        $url = '/api/v2/media-control/auth/cloud-access';
        $data = ['hub_user_id' => $user->id, 'client_id' => 'media-control'];
        $this->withToken(self::SERVICE_TOKEN)->postJson($url, $data)->assertOk()->assertJsonPath('has_cloud_access', false)->assertJsonPath('nextcloud_uid', null);
        $user->givePermissionTo(Permission::findOrCreate('app.cloud.access', 'web'));
        $this->postJson($url, $data)->assertOk()->assertJsonPath('has_cloud_access', false);
        \App\Models\OidcAccountLink::query()->create(['application' => 'cloud', 'user_id' => $user->id,
            'employee_profile_id' => $user->employee_profile_id, 'external_uid' => 'existing-local-uid']);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('has_cloud_access', true)->assertJsonPath('nextcloud_uid', 'existing-local-uid');
        $user->revokePermissionTo('app.media_control.access');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('has_cloud_access', true);
        $user->revokePermissionTo('app.cloud.access');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('has_cloud_access', false)->assertJsonPath('nextcloud_uid', null);
    }

    public function test_machine_identity_checks_share_authenticated_client_budget_without_counting_bad_credentials(): void
    {
        $user = $this->authorizedUser();
        $data = ['hub_user_id' => $user->id, 'client_id' => 'media-control'];
        $key = 'federation-identity-client:media-control';
        $this->withToken('incorrect')->postJson('/api/v2/media-control/auth/cloud-access', $data)->assertUnauthorized();
        self::assertSame(0, \Illuminate\Support\Facades\RateLimiter::attempts($key));
        for ($attempt = 0; $attempt < 6000; $attempt++) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }
        $this->withToken(self::SERVICE_TOKEN)->postJson('/api/v2/media-control/auth/cloud-access', $data)->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/api/v2/media-control/auth/revalidate', [...$data, 'member_id' => $user->employee_profile_id,
            'security_version' => (int) $user->security_version])->assertStatus(429);
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        $this->postJson('/api/v2/media-control/auth/cloud-access', $data)->assertOk();
    }

    public function test_media_revoke_and_regrant_cannot_resurrect_old_code_or_session(): void
    {
        $user = $this->authorizedUser();
        $actor = User::factory()->create(['account_status' => 'active', 'password' => Hash::make('Media-admin-test!')]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $broker = app(\App\Services\MediaControl\MediaControlAuthorizationCodeBroker::class);
        $oldCode = $broker->issue($user, 'media-control', self::CALLBACK);
        $service = app(\App\Services\Security\ApplicationAccessService::class);
        $service->syncApplications($actor, $user, [], 'Media-admin-test!', 'Revoke Media access');
        $service->syncApplications($actor, $user, ['media_control'], 'Media-admin-test!', 'Restore Media access');
        self::assertSame(1, $user->fresh()->media_control_security_version);
        self::assertSame((int) $user->security_version, (int) $user->fresh()->security_version);
        $this->exchange($oldCode)->assertUnauthorized();
        $data = ['hub_user_id' => $user->id, 'security_version' => (int) $user->security_version,
            'member_id' => $user->employee_profile_id, 'client_id' => 'media-control', 'media_control_security_version' => 0];
        $this->withToken(self::SERVICE_TOKEN)->postJson('/api/v2/media-control/auth/revalidate', $data)->assertUnauthorized();
        $this->postJson('/api/v2/media-control/auth/revalidate', [...$data, 'media_control_security_version' => 1])->assertOk();
        $this->exchange($broker->issue($user->fresh(), 'media-control', self::CALLBACK))->assertOk()
            ->assertJsonPath('media_control_security_version', 1);
    }

    private function authorizedUser(string $employeeId = 'MEDIA-1001'): User
    {
        $user = $this->linkedUser($employeeId);
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('app.media_control.access', 'web'));

        return $user;
    }

    private function linkedUser(string $employeeId = 'MEDIA-1001'): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Canonical Media Operator',
            'rank' => 'Firefighter',
            'password' => Hash::make('unrelated-legacy-employee-password'),
            'must_change_password' => false,
        ]);

        $user = User::factory()->create([
            'employee_id' => $employeeId,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('canonical-user-password'),
            'security_version' => 1,
        ])->load('employeeProfile');

        // Federation cases represent members who already reviewed their email.
        // This acknowledgment does not verify or publish the proposed address.
        app(CityEmailVerificationService::class)->acknowledge(
            $user,
            strtolower($employeeId).'@miamibeachfl.gov',
        );

        return $user;
    }

    private function canonicalLogin(User $user): void
    {
        $employeeId = $user->employeeProfile?->employee_id;
        self::assertIsString($employeeId);

        $this->post('/login', [
            'employee_id' => $employeeId,
            'password' => 'canonical-user-password',
        ])->assertRedirect('/');

        $this->withCookie(
            (string) config('session.cookie'),
            $this->app['session.store']->getId(),
        )->withCredentials();
    }

    private function authorizeUrl(
        string $state = self::STATE,
        string $callback = self::CALLBACK,
    ): string {
        return '/auth/media-control/authorize?'.http_build_query([
            'client_id' => 'media-control',
            'redirect_uri' => $callback,
            'state' => $state,
        ]);
    }

    private function issuedCode(): string
    {
        $location = $this->get($this->authorizeUrl())
            ->assertRedirect()
            ->headers->get('Location');
        self::assertIsString($location);
        $query = $this->redirectQuery($location);
        self::assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function exchange(string $code, string $clientId = 'media-control')
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::SERVICE_TOKEN])
            ->postJson('/api/v2/media-control/auth/exchange', [
                'code' => $code,
                'client_id' => $clientId,
                'redirect_uri' => self::CALLBACK,
            ]);
    }

    /** @return array<string, string> */
    private function redirectQuery(string $location): array
    {
        $query = parse_url($location, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parsed);

        return array_filter($parsed, 'is_string');
    }
}
