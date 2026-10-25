<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\Employee;
use App\Models\OidcSession;
use App\Models\User;
use App\Services\Oidc\OidcSessionRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Tests\TestCase;

/**
 * Prompt Studio signs in any active canonical Hub account, with no Hub
 * permission, role or rank. Every other Hub OIDC safeguard still applies.
 */
final class PromptStudioOidcTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://prompt.mbfdhub.com/auth/callback';

    private static int $sequence = 0;

    public function test_a_linked_member_with_no_role_rank_or_grant_signs_in_and_the_hub_identity_is_untouched(): void
    {
        [$user, $client] = $this->linkedFixture();
        self::assertSame([], $user->getRoleNames()->all());
        self::assertFalse($user->hasDirectWebPermission('app.prompt_studio.access'));
        $identity = $user->fresh()->getAttributes();

        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->assertJsonMissingPath('refresh_token')->json();

        $claims = $this->idTokenClaims($token['id_token']);
        self::assertSame('hub-user:'.$user->id, $claims->sub);
        self::assertSame($client->id, $claims->aud);
        self::assertSame('https://mbfdhub.com', $claims->iss);
        self::assertSame('test-nonce', $claims->nonce);
        self::assertSame('prompt_studio', $claims->application);
        self::assertFalse(property_exists($claims, 'command_access'));
        self::assertFalse(property_exists($claims, 'email'));
        $this->withToken($token['access_token'])->getJson('/oauth/userinfo')->assertOk()
            ->assertJsonPath('sub', 'hub-user:'.$user->id)->assertJsonPath('application', 'prompt_studio')
            ->assertJsonPath('security_version', 1)->assertJsonMissingPath('email')->assertJsonMissingPath('role')
            ->assertJsonMissingPath('command_access');
        self::assertSame($identity, $user->fresh()->getAttributes());
        self::assertFalse($user->fresh()->hasDirectWebPermission('app.prompt_studio.access'));
        self::assertSame([], $user->fresh()->getRoleNames()->all());
    }

    public function test_an_active_canonical_user_without_a_personnel_link_may_sign_in(): void
    {
        [$user, $client] = $this->unlinkedFixture();

        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json();

        $claims = $this->idTokenClaims($token['id_token']);
        self::assertSame('hub-user:'.$user->id, $claims->sub);
        self::assertFalse(property_exists($claims, 'employee_id'));
        $session = OidcSession::query()->sole();
        self::assertNull($session->employee_profile_id);
        self::assertSame('prompt_studio', $session->application);
        $this->withToken($token['access_token'])->getJson('/oauth/userinfo')->assertOk()
            ->assertJsonPath('sub', 'hub-user:'.$user->id)->assertJsonMissingPath('employee_id');
    }

    public function test_the_hub_session_does_not_need_any_command_or_other_application_entitlement(): void
    {
        [$user, $client] = $this->linkedFixture();
        $cmd = $this->otherClient('cmd', 'https://cmd.mbfdhub.com/auth/callback');
        $cloud = $this->otherClient('cloud', 'https://cloud.mbfdhub.com/auth/callback');
        $meeting = $this->otherClient('meeting', 'https://meet.mbfdhub.com/auth/callback');

        $this->exchange($client, $this->code($user, $client))->assertOk();
        // The same member remains denied everywhere they were denied before.
        $this->assertNoCodeIssued($this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($cmd))));
        foreach ([$cloud, $meeting] as $other) {
            $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($other)))->assertStatus(401);
        }
    }

    public function test_disabled_password_setup_and_departed_accounts_are_denied_at_every_step(): void
    {
        foreach ([
            fn (User $user) => User::query()->whereKey($user->id)->update(['account_status' => 'disabled']),
            fn (User $user) => User::query()->whereKey($user->id)->update(['must_change_password' => true]),
            fn (User $user) => Employee::query()->whereKey($user->employee_profile_id)->update(['roster_status' => 'departed']),
        ] as $break) {
            [$user, $client] = $this->linkedFixture();
            $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
            $pending = $this->code($user, $client);
            $break($user);
            $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
            $this->exchange($client, $pending)->assertStatus(400);

        }
    }

    public function test_a_mismatched_or_half_linked_employee_association_is_denied(): void
    {
        [$user, $client] = $this->linkedFixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        User::query()->whereKey($user->id)->update(['employee_id' => 'SOMEONE-ELSE']);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->assertNoCodeIssued($this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client))));

        [$user, $client] = $this->linkedFixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        User::query()->whereKey($user->id)->update(['employee_profile_id' => null]);
        // Unlinked while still claiming a personnel number: the identity is inconsistent.
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();

        [$user, $client] = $this->unlinkedFixture();
        User::query()->whereKey($user->id)->update(['employee_id' => 'CLAIMED-123']);
        $this->assertNoCodeIssued($this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client))));
    }

    public function test_security_version_change_invalidates_pending_code_and_issued_token(): void
    {
        [$user, $client] = $this->linkedFixture();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $pending = $this->code($user, $client);

        User::query()->whereKey($user->id)->update(['security_version' => 2]);

        $this->exchange($client, $pending)->assertStatus(400);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_revoked_client_wrong_secret_wrong_verifier_and_foreign_redirect_are_denied(): void
    {
        [$user, $client] = $this->linkedFixture();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), 'redirect_uri' => 'https://attacker.invalid/auth/callback']))
            ->assertUnauthorized();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), 'redirect_uri' => 'https://cmd.mbfdhub.com/auth/callback']))
            ->assertUnauthorized();
        foreach ([['code_challenge_method' => 'plain'], ['nonce' => ''], ['state' => '']] as $bad) {
            $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), ...$bad]))->assertStatus(400);
        }
        $code = $this->code($user, $client);
        $this->exchange($client, $code, ['code_verifier' => str_repeat('x', 64)])->assertStatus(400);
        $this->exchange($client, $code, ['client_secret' => 'bad'])->assertUnauthorized();
        $token = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $client->update(['revoked' => true]);
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertUnauthorized();
    }

    public function test_a_code_is_single_use_and_only_authorization_code_grants_exist(): void
    {
        [$user, $client] = $this->linkedFixture();
        $code = $this->code($user, $client);
        $this->exchange($client, $code)->assertOk();
        $this->exchange($client, $code)->assertStatus(400);
        foreach (['refresh_token', 'client_credentials', 'password'] as $grant) {
            $this->postJson('/oauth/token', ['client_id' => $client->id, 'client_secret' => 'test-client-secret', 'grant_type' => $grant])
                ->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        }
    }

    public function test_one_client_id_configured_for_two_applications_is_ambiguous_and_denied(): void
    {
        [$user, $client] = $this->linkedFixture();
        config(['oidc.clients.meeting' => $client->id]);

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertUnauthorized();
    }

    public function test_application_scoped_revocation_leaves_other_applications_alone_and_account_wide_revocation_ends_prompt_studio(): void
    {
        [$user, $client] = $this->linkedFixture();
        $user->employeeProfile->forceFill(['rank' => 'Captain'])->save();
        $cmd = $this->otherClient('cmd', 'https://cmd.mbfdhub.com/auth/callback');
        $promptToken = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $cmdToken = $this->exchange($cmd, $this->code($user, $cmd))->assertOk()->json('access_token');

        app(OidcSessionRevoker::class)->revoke($user, 'cmd');
        $this->withToken($cmdToken)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->withToken($promptToken)->getJson('/oauth/userinfo')->assertOk()->assertJsonPath('application', 'prompt_studio');

        app(OidcSessionRevoker::class)->revoke($user, 'prompt_studio');
        $this->withToken($promptToken)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->assertDatabaseMissing('oidc_sessions', ['user_id' => $user->id, 'application' => 'prompt_studio', 'revoked_at' => null]);

        $again = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        app(OidcSessionRevoker::class)->revoke($user);
        $this->withToken($again)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_email_is_released_only_when_the_client_asks_for_the_email_scope(): void
    {
        [$user, $client] = $this->linkedFixture();
        $profile = $this->exchange($client, $this->code($user, $client))->assertOk()->json('access_token');
        $this->withToken($profile)->getJson('/oauth/userinfo')->assertOk()->assertJsonMissingPath('email')->assertJsonPath('name', $user->display_name ?: $user->name);
        $withEmail = $this->exchange($client, $this->code($user, $client, ['scope' => 'openid profile email']))->assertOk()->json('access_token');
        $this->withToken($withEmail)->getJson('/oauth/userinfo')->assertOk()->assertJsonPath('email_verified', false);
    }

    /** A denied or unauthenticated authorize request never redirects back to the application with a code. */
    private function assertNoCodeIssued(TestResponse $response): void
    {
        self::assertNotSame(200, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringNotContainsString('code=', $location);
    }

    public function test_a_same_origin_login_chain_ends_in_a_handoff_page_not_a_cross_origin_redirect(): void
    {
        $this->withoutVite(); // the handoff page uses the shared auth layout; assets are not built in tests
        [$user, $client] = $this->linkedFixture();
        $url = '/oauth/authorize?'.http_build_query($this->authorizationQuery($client));

        // The browser marks the redirect chain that started at Hub's own login form as same-origin.
        $response = $this->actingAs($user)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->get($url);

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Opening your MBFD application');
        $destination = html_entity_decode((string) preg_replace('/.*<a href="([^"]+)".*/s', '$1', $response->getContent()));
        self::assertStringStartsWith(self::CALLBACK.'?', $destination);
        parse_str((string) parse_url($destination, PHP_URL_QUERY), $returned);
        self::assertSame('test-state', $returned['state']);
        self::assertNotEmpty($returned['code']);
        // The handoff carries a real, single-use code: it exchanges exactly once.
        $this->exchange($client, $returned['code'])->assertOk();
        $this->exchange($client, $returned['code'])->assertStatus(400);
    }

    public function test_other_navigations_keep_the_plain_redirect_and_denials_never_leak_a_code_through_the_handoff(): void
    {
        [$user, $client] = $this->linkedFixture();
        $query = $this->authorizationQuery($client);
        foreach ([[], ['Sec-Fetch-Site' => 'same-site'], ['Sec-Fetch-Site' => 'cross-site']] as $headers) {
            $this->actingAs($user)->withHeaders($headers)->get('/oauth/authorize?'.http_build_query($query))
                ->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
        }
        // A request that fails validation is never turned into a handoff page.
        $this->actingAs($user)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->get('/oauth/authorize?'.http_build_query([...$query, 'redirect_uri' => 'https://attacker.invalid/auth/callback']))->assertUnauthorized();
    }

    private function idTokenClaims(string $idToken): object
    {
        $jwks = $this->getJson('/oauth/jwks')->assertOk()->json();

        return \Firebase\JWT\JWT::decode($idToken, \Firebase\JWT\JWK::parseKeySet($jwks, 'RS256'));
    }

    private function code(User $user, Client $client, array $overrides = []): string
    {
        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$this->authorizationQuery($client), ...$overrides]))->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $returned);

        return $returned['code'];
    }

    private function exchange(Client $client, string $code, array $overrides = []): TestResponse
    {
        return $this->postJson('/oauth/token', [...[
            'grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => 'test-client-secret',
            'redirect_uri' => $client->redirect_uris[0], 'code' => $code, 'code_verifier' => str_repeat('v', 64),
        ], ...$overrides]);
    }

    private function configureKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
    }

    private function otherClient(string $application, string $callback): Client
    {
        $client = Client::query()->create(['name' => $application.' isolation test', 'secret' => 'test-client-secret', 'provider' => 'users',
            'redirect_uris' => [$callback], 'grant_types' => ['authorization_code'], 'revoked' => false]);
        config(['oidc.clients.'.$application => $client->id]);

        return $client;
    }

    /** @return array{User, Client} */
    private function linkedFixture(): array
    {
        $this->configureKeys();
        $n = ++self::$sequence;
        $employee = Employee::query()->create(['name' => 'Prompt Test '.$n, 'employee_id' => 'PS-99'.$n, 'rank' => 'Firefighter',
            'city_email' => 'promptstudio.test'.$n.'@miamibeachfl.gov', 'password' => bcrypt('Test-password-only!')]);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'email' => $employee->city_email, 'account_status' => 'active', 'security_version' => 1, 'must_change_password' => false]);
        $client = $this->promptStudioClient();
        $this->actingAsCanonicalUser($user);

        return [$user, $client];
    }

    /** @return array{User, Client} */
    private function unlinkedFixture(): array
    {
        $this->configureKeys();
        $user = User::factory()->create(['employee_profile_id' => null, 'employee_id' => null, 'account_status' => 'active',
            'security_version' => 1, 'must_change_password' => false]);
        $client = $this->promptStudioClient();
        $this->actingAsCanonicalUser($user);

        return [$user, $client];
    }

    private function promptStudioClient(): Client
    {
        $client = Client::query()->create(['name' => 'prompt studio test', 'secret' => 'test-client-secret', 'provider' => 'users',
            'redirect_uris' => [self::CALLBACK], 'grant_types' => ['authorization_code'], 'revoked' => false]);
        config(['oidc.clients.prompt_studio' => $client->id]);

        return $client;
    }

    /** @return array<string, string> */
    private function authorizationQuery(Client $client): array
    {
        return ['client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code',
            'scope' => 'openid profile', 'state' => 'test-state', 'nonce' => 'test-nonce', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '=')];
    }
}
