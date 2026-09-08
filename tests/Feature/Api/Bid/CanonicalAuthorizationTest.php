<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Bid;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CityEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://staging.bid.mbfdhub.com/api/auth/callback';

    private const FEDERATION_TOKEN = 'test-bid-federation-secret-do-not-use-in-prod';

    private const READER_TOKEN = 'test-bid-reader-secret-do-not-use-in-prod';

    private const STATE = 'uQxS6x3Mki8aUHsi_vB1m2zY9kt_P4DSxMZ0nNfw2-I';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.bid.federation_token', self::FEDERATION_TOKEN);
        config()->set('services.bid.reader_token', self::READER_TOKEN);
        config()->set('services.bid.authorization.issuer', 'https://www.mbfdhub.com');
        config()->set('services.bid.authorization.code_ttl_seconds', 60);
        config()->set('services.bid.authorization.clients.bid.callbacks', [
            'https://bid.mbfdhub.com/api/auth/callback',
            self::CALLBACK,
        ]);
    }

    public function test_unauthenticated_authorization_uses_the_canonical_login_flow(): void
    {
        $this->get($this->authorizeUrl())
            ->assertRedirect('/login');
    }

    public function test_existing_canonical_user_resumes_the_exact_bid_authorize_destination_after_login(): void
    {
        $authorizeUrl = $this->authorizeUrl();
        $user = $this->linkedUser();

        $this->get($authorizeUrl)->assertRedirect('/login');
        $loginLocation = $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'canonical-user-password',
        ])->assertRedirect()->headers->get('Location');
        self::assertIsString($loginLocation);
        self::assertSame('/auth/bid/authorize', parse_url($loginLocation, PHP_URL_PATH));
        $loginQuery = $this->redirectQuery($loginLocation);
        self::assertSame('bid', $loginQuery['client_id'] ?? null);
        self::assertSame(self::CALLBACK, $loginQuery['redirect_uri'] ?? null);
        self::assertSame(self::STATE, $loginQuery['state'] ?? null);
        $this->withCookie(
            (string) config('session.cookie'),
            $this->app['session.store']->getId(),
        )->withCredentials();

        $location = $this->handoffDestination($loginLocation);
        self::assertIsString($location);
        self::assertStringStartsWith(self::CALLBACK.'?', $location);

        $query = $this->redirectQuery($location);
        self::assertSame(self::STATE, $query['state'] ?? null);
        self::assertIsString($query['code'] ?? null);
        $this->exchange($query['code'])->assertOk();
    }

    public function test_first_login_canonicalization_preserves_the_bid_authorize_destination_through_activation(): void
    {
        $authorizeUrl = $this->authorizeUrl();
        $employee = Employee::query()->create([
            'employee_id' => 'BID-FIRST-LOGIN',
            'name' => 'First Login Bid Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('employee-legacy-password'),
            'must_change_password' => false,
        ]);

        $this->get($authorizeUrl)->assertRedirect('/login');
        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-legacy-password',
        ])->assertRedirect('/activate-account');

        $activation = $this->get('/activate-account')->assertOk();
        $nonce = $activation->viewData('nonce');
        self::assertIsString($nonce);
        $this->post('/activate-account', [
            'nonce' => $nonce,
            'path' => 'no_existing_user',
            'no_legacy_account_assertion' => '1',
        ])->assertRedirect($authorizeUrl);

        $user = $employee->fresh()->user;
        self::assertInstanceOf(User::class, $user);
        $user->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('authentication_sessions', ['user_id' => $user->id]);
        $this->withCookie(
            (string) config('session.cookie'),
            $this->app['session.store']->getId(),
        )->withCredentials();

        // A newly activated member reviews their address before returning to
        // the exact Bid authorization request. Delivery does not gate app access.
        Http::fake();
        config()->set('communications.cloudflare.api_token', '');
        $this->get($authorizeUrl)->assertRedirect('/account/city-email');
        $this->post('/account/city-email', [
            'email' => 'firstloginbidmember@miamibeachfl.gov',
            'current_password' => 'employee-legacy-password',
            'ownership_confirmed' => '1',
        ])->assertRedirect('/account/city-email')->assertSessionHasNoErrors();
        $this->post('/account/city-email/continue')->assertRedirect($authorizeUrl);
        self::assertNull($user->fresh()->employeeProfile->city_email);
        Http::assertNothingSent();

        $location = $this->handoffDestination($authorizeUrl);
        self::assertIsString($location);
        self::assertStringStartsWith(self::CALLBACK.'?', $location);

        $query = $this->redirectQuery($location);
        self::assertSame(self::STATE, $query['state'] ?? null);
        self::assertIsString($query['code'] ?? null);
        $this->exchange($query['code'])->assertOk();
    }

    public function test_active_canonical_user_with_linked_employee_receives_and_redeems_an_opaque_code(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);

        $location = $this->handoffDestination($this->authorizeUrl());
        self::assertIsString($location);
        self::assertStringStartsWith(self::CALLBACK.'?', $location);

        $query = $this->redirectQuery($location);
        self::assertSame(self::STATE, $query['state'] ?? null);
        self::assertIsString($query['code'] ?? null);
        self::assertGreaterThanOrEqual(43, strlen($query['code']));
        self::assertNotSame((string) $user->id, $query['code']);
        self::assertStringNotContainsString((string) $user->employeeProfile->employee_id, $query['code']);

        $response = $this->exchange($query['code']);
        $response->assertOk()->assertJson([
            'issuer' => 'https://www.mbfdhub.com',
            'audience' => 'bid',
            'hub_user_id' => $user->id,
            'security_version' => 1,
            'member_id' => $user->employeeProfile->id,
            'employee_id' => $user->employeeProfile->employee_id,
            'first_name' => 'Canonical',
            'last_name' => 'Bid Member',
            'rank' => 'Firefighter',
            'role' => 'member',
        ]);

        $payload = $response->json();
        foreach (['password', 'password_hash', 'session', 'session_id', 'remember_token'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payload);
        }
    }

    public function test_disabled_and_revoked_canonical_sessions_are_denied_before_code_issuance(): void
    {
        $disabled = $this->linkedUser('DISABLED');
        $this->canonicalLogin($disabled);
        $disabled->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $this->get($this->authorizeUrl())
            ->assertRedirect('/login');

        $revoked = $this->linkedUser('REVOKED');
        $this->canonicalLogin($revoked);
        AuthenticationSession::query()
            ->where('user_id', $revoked->id)
            ->update(['revoked_at' => now()]);

        $this->get($this->authorizeUrl())
            ->assertRedirect('/login');
    }

    public function test_production_callback_uses_the_same_guarded_handoff(): void
    {
        $this->canonicalLogin($this->linkedUser());
        $callback = 'https://bid.mbfdhub.com/api/auth/callback';
        $destination = $this->handoffDestination($this->authorizeUrl(callback: $callback));
        self::assertStringStartsWith($callback.'?', $destination);
        $query = $this->redirectQuery($destination);
        self::assertSame(self::STATE, $query['state'] ?? null);
        $this->exchange($query['code'], callback: $callback)->assertOk();
    }

    public function test_handoff_preserves_access_denied_for_users_without_bid_entitlement(): void
    {
        $user = $this->linkedUser();
        $user->revokePermissionTo('app.bid.access');
        $this->canonicalLogin($user);
        $query = $this->redirectQuery($this->handoffDestination($this->authorizeUrl()));
        self::assertSame('access_denied', $query['error'] ?? null);
        self::assertSame(self::STATE, $query['state'] ?? null);
        self::assertArrayNotHasKey('code', $query);
    }

    public function test_unlinked_user_fails_closed_without_identity_inference(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);
        $user->forceFill(['employee_profile_id' => null])->save();
        $this->app['auth']->forgetGuards();

        $location = $this->handoffDestination($this->authorizeUrl());
        self::assertIsString($location);

        $query = $this->redirectQuery($location);
        self::assertSame('access_denied', $query['error'] ?? null);
        self::assertSame(self::STATE, $query['state'] ?? null);
        self::assertArrayNotHasKey('code', $query);
    }

    public function test_only_registered_callback_and_well_formed_state_are_accepted(): void
    {
        $this->canonicalLogin($this->linkedUser());

        foreach ([
            'https://evil.example/api/auth/callback',
            '//staging.bid.mbfdhub.com/api/auth/callback',
            'https://staging.bid.mbfdhub.com.evil.example/api/auth/callback',
            'https://staging.bid.mbfdhub.com/api/auth/callback/alternate',
        ] as $callback) {
            $this->withHeader('Accept', 'application/json')
                ->get($this->authorizeUrl(callback: $callback))
                ->assertUnprocessable();
        }

        $this->withHeader('Accept', 'application/json')
            ->get('/auth/bid/authorize?client_id=bid&redirect_uri='.rawurlencode(self::CALLBACK))
            ->assertUnprocessable();
    }

    public function test_code_is_short_lived_single_use_audience_and_callback_bound_and_tamper_resistant(): void
    {
        $this->canonicalLogin($this->linkedUser());

        $firstCode = $this->issuedCode();
        $this->exchange($firstCode)->assertOk();
        $this->exchange($firstCode)->assertUnauthorized();

        $tamperCode = $this->issuedCode();
        $modified = substr($tamperCode, 0, -1).($tamperCode[-1] === 'A' ? 'B' : 'A');
        $this->exchange($modified)->assertUnauthorized();
        $this->exchange($tamperCode)->assertOk();

        $audienceCode = $this->issuedCode();
        $this->exchange($audienceCode, clientId: 'another-app')->assertUnauthorized();
        $this->exchange($audienceCode)->assertOk();

        $callbackCode = $this->issuedCode();
        $this->exchange(
            $callbackCode,
            callback: 'https://bid.mbfdhub.com/api/auth/callback',
        )->assertUnauthorized();
        $this->exchange($callbackCode)->assertOk();

        $expiredCode = $this->issuedCode();
        $this->travel(61)->seconds();
        $this->exchange($expiredCode)->assertUnauthorized();
    }

    public function test_bid_role_is_derived_from_current_explicit_admin_entitlement(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = $this->linkedUser();
        $this->canonicalLogin($user);

        $code = $this->issuedCode();
        $user->assignRole('admin');
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->exchange($code)
            ->assertOk()
            ->assertJson(['role' => 'admin']);
    }

    public function test_code_cannot_outlive_canonical_security_version(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);
        $securityVersionCode = $this->issuedCode();
        User::query()->whereKey($user->id)->increment('security_version');

        $this->exchange($securityVersionCode)->assertUnauthorized();
    }

    public function test_code_cannot_outlive_canonical_account_status(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);
        $disabledCode = $this->issuedCode();
        $user->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $this->exchange($disabledCode)->assertUnauthorized();
    }

    public function test_code_cannot_outlive_canonical_employee_linkage(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);
        $code = $this->issuedCode();
        $replacement = Employee::query()->create([
            'employee_id' => 'BID-RELINKED',
            'name' => 'Relinked Bid Member',
            'rank' => 'Captain',
            'password' => Hash::make('replacement-test-credential'),
            'must_change_password' => false,
        ]);
        $user->forceFill(['employee_profile_id' => $replacement->id])->save();

        $this->exchange($code)->assertUnauthorized();
    }

    public function test_exchange_requires_the_dedicated_federation_credential(): void
    {
        $this->canonicalLogin($this->linkedUser());
        $code = $this->issuedCode();
        $payload = [
            'code' => $code,
            'client_id' => 'bid',
            'redirect_uri' => self::CALLBACK,
        ];

        $this->postJson('/api/v2/bid/auth/exchange', $payload)->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
            ->postJson('/api/v2/bid/auth/exchange', $payload)
            ->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer '.self::READER_TOKEN])
            ->postJson('/api/v2/bid/auth/exchange', $payload)
            ->assertUnauthorized();

        $this->exchange($code)->assertOk();

        $unconfiguredCode = $this->issuedCode();
        config()->set('services.bid.federation_token', null);
        $this->exchange($unconfiguredCode)
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'bid_federation_unavailable']);
    }

    private function linkedUser(string $employeeId = 'BID-1001'): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Canonical Bid Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('different-legacy-employee-password'),
            'must_change_password' => false,
        ]);

        $user = User::factory()->create([
            'employee_id' => $employeeId,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('canonical-user-password'),
            'security_version' => 1,
        ])->load('employeeProfile');
        $user->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        // These federation cases use an existing member who already reviewed
        // their address. First-time review is exercised through HTTP above.
        app(CityEmailVerificationService::class)->acknowledge(
            $user,
            strtolower($employeeId).'@miamibeachfl.gov',
        );

        return $user;
    }

    private function canonicalLogin(User $user): void
    {
        $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
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
        return '/auth/bid/authorize?'.http_build_query([
            'client_id' => 'bid',
            'redirect_uri' => $callback,
            'state' => $state,
        ]);
    }

    private function handoffDestination(string $url): string
    {
        $response = $this->get($url)
            ->assertOk()
            ->assertViewIs('auth.bid-handoff')
            ->assertHeaderMissing('Location');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString("form-action 'self';", (string) $response->headers->get('Content-Security-Policy'));
        $destination = $response->viewData('destination');
        self::assertIsString($destination);
        $response->assertSee('content="0;url='.e($destination).'"', false);
        $response->assertSee('href="'.e($destination).'"', false);
        $response->assertSee('name="referrer" content="no-referrer"', false);

        return $destination;
    }

    private function issuedCode(): string
    {
        $location = $this->handoffDestination($this->authorizeUrl());
        self::assertIsString($location);
        $query = $this->redirectQuery($location);
        self::assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function exchange(
        string $code,
        string $clientId = 'bid',
        string $callback = self::CALLBACK,
    ) {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::FEDERATION_TOKEN])
            ->postJson('/api/v2/bid/auth/exchange', [
                'code' => $code,
                'client_id' => $clientId,
                'redirect_uri' => $callback,
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
