<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class HubOidcProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_advertises_only_implemented_secure_flow(): void
    {
        $this->getJson('/.well-known/openid-configuration')->assertOk()
            ->assertJsonPath('issuer', 'https://mbfdhub.com')
            ->assertJsonPath('grant_types_supported', ['authorization_code'])
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_oidc_schema_rolls_back_and_reapplies_without_touching_existing_identities_or_grants(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_09_08_160000_create_hub_oidc_tables.php');
        $migration->down();
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasTable('oidc_sessions'));
        $migration->up();
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('oidc_sessions'));
        self::assertSame($before, $user->fresh()->getAttributes());
        self::assertFalse($user->hasDirectWebPermission('app.cmd.access'));
        self::assertFalse($user->hasDirectWebPermission('app.cloud.access'));
        $this->assertDatabaseCount('oauth_clients', 0);
        $this->assertDatabaseCount('oidc_account_links', 0);
    }

    public function test_code_exchange_and_userinfo_preserve_identity_and_recheck_revocation(): void
    {
        [$user, $client] = $this->fixture();
        $identity = $user->fresh()->getAttributes();
        $query = $this->authorizationQuery($client);
        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $returned);
        self::assertSame('test-state', $returned['state']);
        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => 'test-client-secret',
            'redirect_uri' => $query['redirect_uri'], 'code' => $returned['code'], 'code_verifier' => str_repeat('v', 64),
        ])->assertOk()->assertJsonMissingPath('refresh_token')->json();
        $claims = json_decode(base64_decode(strtr(explode('.', $token['id_token'])[1], '-_', '+/')), true);
        self::assertSame('hub-user:'.$user->id, $claims['sub']);
        self::assertSame('test-nonce', $claims['nonce']);
        self::assertSame((string) $user->employee_id, $claims['employee_id']);
        self::assertSame(300, $claims['exp'] - $claims['iat']);
        self::assertSame(28800, $token['expires_in']);
        $this->withToken($token['access_token'])->getJson('/oauth/userinfo')->assertOk()
            ->assertJsonPath('sub', $claims['sub'])->assertJsonPath('security_version', 1)->assertJsonMissingPath('email');
        self::assertSame($identity, $user->fresh()->getAttributes());
        $user->revokePermissionTo('app.cmd.access');
        $this->withToken($token['access_token'])->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_confidential_basic_client_auth_nonce_signature_and_single_use(): void
    {
        [$user, $client] = $this->fixture();
        $query = $this->authorizationQuery($client);
        $code = $this->code($user, $client);
        $data = ['grant_type' => 'authorization_code', 'redirect_uri' => $query['redirect_uri'], 'code' => $code, 'code_verifier' => str_repeat('v', 64)];
        $token = $this->withHeaders(['Authorization' => 'Basic '.base64_encode($client->id.':test-client-secret')])
            ->post('/oauth/token', $data)->assertOk()->json();
        $jwks = $this->getJson('/oauth/jwks')->assertOk()->json();
        $claims = \Firebase\JWT\JWT::decode($token['id_token'], \Firebase\JWT\JWK::parseKeySet($jwks, 'RS256'));
        self::assertSame('https://mbfdhub.com', $claims->iss);
        self::assertSame($client->id, $claims->aud);
        self::assertSame('test-nonce', $claims->nonce);
        self::assertFalse(property_exists($claims, 'email'));
        $this->post('/oauth/token', $data)->assertStatus(400);
    }

    public function test_email_verified_requires_current_email_bound_mailbox_proof(): void
    {
        [$user, $client] = $this->fixture();
        $token = $this->exchange($client, $this->code($user, $client, ['scope' => 'openid email']))->assertOk()->json('access_token');
        $this->withToken($token)->getJson('/oauth/userinfo')->assertOk()->assertJsonPath('email_verified', false);
        $proof = app(\App\Services\Identity\CityEmailVerificationService::class)->acknowledge($user, $user->email);
        $proof->forceFill(['verified_at' => now(), 'delivery_status' => 'verified'])->save();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertOk()->assertJsonPath('email_verified', true);
        $user->employeeProfile->forceFill(['city_email' => 'different@miamibeachfl.gov'])->save();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertOk()->assertJsonPath('email', 'different@miamibeachfl.gov')->assertJsonPath('email_verified', false);
    }

    public function test_wrong_pkce_callback_client_and_missing_entitlement_are_denied(): void
    {
        [$user, $client] = $this->fixture();
        foreach ([['code_challenge_method' => 'plain'], ['nonce' => '']] as $bad) {
            $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), ...$bad]))->assertStatus(400);
        }
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), 'redirect_uri' => 'https://attacker.invalid/callback']))->assertUnauthorized();
        $code = $this->code($user, $client);
        $this->exchange($client, $code, ['code_verifier' => str_repeat('x', 64)])->assertStatus(400);
        $this->exchange($client, $code, ['client_secret' => 'bad'])->assertUnauthorized();
        $user->revokePermissionTo('app.cmd.access');
        $this->exchange($client, $code)->assertStatus(400);
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertUnauthorized();
    }

    public function test_security_version_change_invalidates_pending_code_and_issued_token(): void
    {
        [$user, $client] = $this->fixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $pending = $this->code($user, $client);
        User::query()->whereKey($user->id)->update(['security_version' => 2]);
        $this->exchange($client, $pending)->assertStatus(400);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_disabled_account_swapped_employee_or_revoked_client_cannot_use_token(): void
    {
        [$user, $client] = $this->fixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        User::query()->whereKey($user->id)->update(['account_status' => 'disabled']);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        User::query()->whereKey($user->id)->update(['account_status' => 'active', 'employee_profile_id' => null]);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        User::query()->whereKey($user->id)->update(['employee_profile_id' => $user->employee_profile_id]);
        $client->update(['revoked' => true]);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_cloud_requires_separate_grant_and_exact_preapproved_existing_account_link(): void
    {
        [$user, $client] = $this->fixture();
        config(['oidc.clients.cmd' => '', 'oidc.clients.cloud' => $client->id]);
        $url = '/oauth/authorize?'.http_build_query($this->authorizationQuery($client));
        $this->actingAs($user)->get($url)->assertUnauthorized();
        $user->givePermissionTo(Permission::findOrCreate('app.cloud.access', 'web'));
        $this->get($url)->assertUnauthorized();
        \App\Models\OidcAccountLink::query()->create(['application' => 'cloud', 'user_id' => $user->id,
            'employee_profile_id' => $user->employee_profile_id, 'external_uid' => 'existing-cloud-account']);
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $this->withToken($token)->getJson('/oauth/userinfo')->assertOk()->assertJsonPath('application', 'cloud')
            ->assertJsonPath('nextcloud_uid', 'existing-cloud-account');
        \App\Models\OidcAccountLink::query()->where('user_id', $user->id)->update(['external_uid' => 'changed-cloud-account']);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_refresh_and_client_credentials_grants_are_not_enabled(): void
    {
        [, $client] = $this->fixture();
        foreach (['refresh_token', 'client_credentials', 'password'] as $grant) {
            $this->postJson('/oauth/token', ['client_id' => $client->id, 'client_secret' => 'test-client-secret', 'grant_type' => $grant])
                ->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        }
    }

    public function test_unsupported_authentication_requirements_are_not_silently_ignored(): void
    {
        [$user, $client] = $this->fixture();
        foreach ([['prompt' => 'login'], ['prompt' => 'none'], ['max_age' => '0'], ['request_uri' => 'https://attacker.invalid/request'], ['request' => 'signed-request'], ['response_mode' => 'fragment']] as $unsupported) {
            $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), ...$unsupported]))
                ->assertStatus(400)->assertJsonPath('error', 'invalid_request');
        }
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_expired_or_tampered_access_tokens_are_rejected_even_with_valid_local_binding(): void
    {
        [$user, $client] = $this->fixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $claims['iat'] = time() - 400;
        $claims['nbf'] = time() - 400;
        $claims['exp'] = time() - 1;
        $expired = \Firebase\JWT\JWT::encode($claims, config('passport.private_key'), 'RS256');
        $this->withToken($expired)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->withToken(substr($token, 0, -10).'tampered00')->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertOk();
    }

    public function test_password_and_email_onboarding_retain_exact_oidc_return_path(): void
    {
        [$user, $client] = $this->fixture();
        $url = '/oauth/authorize?'.http_build_query($this->authorizationQuery($client));
        $user->forceFill(['must_change_password' => true])->save();
        $this->actingAs($user)->get($url)->assertRedirect('/employee/set-password');
        self::assertSame($url, session(\App\Services\Identity\CanonicalLoginDestination::PASSWORD_RETURN_KEY));
        self::assertSame($url, app(\App\Services\Identity\CanonicalLoginDestination::class)->resolve($user, $url));
        $user->forceFill(['must_change_password' => false, 'email' => 'employee-99001@canonical.mbfdhub.invalid'])->save();
        $user->employeeProfile->forceFill(['city_email' => null])->save();
        $this->withSession([\App\Http\Middleware\EnsureCityEmailReview::SESSION_KEY => true])->get($url)->assertRedirect(route('city-email.show'));
        self::assertSame($url, session(\App\Http\Middleware\EnsureCityEmailReview::RETURN_KEY));
    }

    public function test_anonymous_oidc_request_uses_existing_employee_id_login_and_returns_to_authorization(): void
    {
        [$user, $client] = $this->fixture();
        $user->forceFill(['password' => bcrypt('Canonical-oidc-login-only!')])->save();
        $url = '/oauth/authorize?'.http_build_query($this->authorizationQuery($client));
        $this->get($url)->assertRedirect(route('login'));
        $login = $this->post('/login', ['employee_id' => $user->employee_id, 'password' => 'Canonical-oidc-login-only!'])->assertRedirect();
        self::assertSame('/oauth/authorize', parse_url($login->headers->get('Location'), PHP_URL_PATH));
        parse_str((string) parse_url($login->headers->get('Location'), PHP_URL_QUERY), $returned);
        self::assertEquals($this->authorizationQuery($client), $returned);
        $this->assertAuthenticatedAs($user, 'web');
        $this->get($url)->assertRedirect();
        $this->assertDatabaseCount('oidc_sessions', 1);
    }

    public function test_revoker_persistently_invalidates_codes_and_tokens(): void
    {
        [$user, $client] = $this->fixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $pending = $this->code($user, $client);
        app(\App\Services\Oidc\OidcSessionRevoker::class)->revoke($user);
        $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => false]);
        $this->assertDatabaseMissing('oauth_auth_codes', ['user_id' => $user->id, 'revoked' => false]);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->exchange($client, $pending)->assertStatus(400);
    }

    public function test_audited_app_revoke_cannot_resurrect_old_tokens_when_access_is_regranted(): void
    {
        [$user, $client] = $this->fixture();
        $actor = User::factory()->create(['account_status' => 'active', 'password' => bcrypt('Test-admin-password!')]);
        $actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $service = app(\App\Services\Security\ApplicationAccessService::class);
        $service->syncApplications($actor, $user, [], 'Test-admin-password!', 'Access removed');
        $service->syncApplications($actor, $user, ['cmd'], 'Test-admin-password!', 'Access restored');
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        $newToken = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $this->withToken($newToken)->getJson('/oauth/userinfo')->assertOk();
    }

    public function test_canonical_security_transition_persistently_revokes_oidc_credentials(): void
    {
        [$user, $client] = $this->fixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        app(\App\Services\Identity\AccountSecurityService::class)->revokeAll($user, 'Test security transition', now());
        $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => false]);
        $this->assertDatabaseMissing('oidc_sessions', ['user_id' => $user->id, 'revoked_at' => null]);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_super_admin_removal_persistently_revokes_tokens_without_changing_direct_grants(): void
    {
        [$user, $client] = $this->fixture();
        $super = \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $user->assignRole($super);
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->assignRole($super);
        config(['security.role_assignment.allow_critical_role_changes' => true]);
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        app(\App\Services\Security\RoleAssignmentService::class)->sync($actor, $user, []);
        self::assertSame(1, $user->fresh()->media_control_security_version);
        self::assertTrue($user->fresh()->hasDirectWebPermission('app.cmd.access'));
        $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => false]);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_userinfo_rate_limit_is_per_authenticated_session_not_shared_consumer_ip(): void
    {
        [$user, $client] = $this->fixture();
        $first = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $session = \App\Models\OidcSession::query()->firstOrFail();
        for ($attempt = 0; $attempt < 120; $attempt++) {
            \Illuminate\Support\Facades\RateLimiter::hit('oidc-userinfo-session:'.$session->id, 60);
        }
        $this->withToken($first)->getJson('/oauth/userinfo')->assertStatus(429)->assertHeader('Retry-After');
        $second = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $this->withToken($second)->getJson('/oauth/userinfo')->assertOk();
        self::assertContains('throttle:6000,1', app('router')->getRoutes()->getByName('oidc.userinfo')->middleware());
    }

    private function code(User $user, Client $client, array $overrides = []): string
    {
        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), ...$overrides]))->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $returned);

        return $returned['code'];
    }

    private function exchange(Client $client, string $code, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/oauth/token', [...[
            'grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => 'test-client-secret',
            'redirect_uri' => 'https://cmd.mbfdhub.com/auth/callback', 'code' => $code, 'code_verifier' => str_repeat('v', 64),
        ], ...$overrides]);
    }

    /** @return array{User, Client} */
    private function fixture(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        $employee = Employee::query()->create(['name' => 'OIDC Test', 'employee_id' => 'OIDC-99001', 'city_email' => 'oidctest@miamibeachfl.gov', 'password' => bcrypt('Test-password-only!')]);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'email' => $employee->city_email, 'account_status' => 'active', 'security_version' => 1, 'must_change_password' => false]);
        $user->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));
        $client = Client::query()->create(['name' => 'CMD test', 'secret' => 'test-client-secret', 'provider' => 'users',
            'redirect_uris' => ['https://cmd.mbfdhub.com/auth/callback'], 'grant_types' => ['authorization_code'], 'revoked' => false]);
        config(['oidc.clients.cmd' => $client->id]);

        return [$user, $client];
    }

    /** @return array<string, string> */
    private function authorizationQuery(Client $client): array
    {
        return ['client_id' => $client->id, 'redirect_uri' => 'https://cmd.mbfdhub.com/auth/callback', 'response_type' => 'code',
            'scope' => 'openid profile', 'state' => 'test-state', 'nonce' => 'test-nonce', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '=')];
    }
}
