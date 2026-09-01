<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Bid;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://staging.bid.mbfdhub.com/api/auth/callback';

    private const READER_TOKEN = 'test-bid-reader-secret-do-not-use-in-prod';

    private const STATE = 'uQxS6x3Mki8aUHsi_vB1m2zY9kt_P4DSxMZ0nNfw2-I';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
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

    public function test_active_canonical_user_with_linked_employee_receives_and_redeems_an_opaque_code(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);

        $location = $this->get($this->authorizeUrl())
            ->assertRedirect()
            ->headers->get('Location');
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

    public function test_unlinked_user_fails_closed_without_identity_inference(): void
    {
        $user = $this->linkedUser();
        $this->canonicalLogin($user);
        $user->forceFill(['employee_profile_id' => null])->save();
        $this->app['auth']->forgetGuards();

        $location = $this->get($this->authorizeUrl())
            ->assertRedirect()
            ->headers->get('Location');
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

    public function test_bid_role_is_derived_from_current_canonical_user_authorization(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = $this->linkedUser();
        $this->canonicalLogin($user);

        $code = $this->issuedCode();
        $user->assignRole('admin');
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

    private function linkedUser(string $employeeId = 'BID-1001'): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Canonical Bid Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('different-legacy-employee-password'),
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'employee_id' => $employeeId,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('canonical-user-password'),
            'security_version' => 1,
        ])->load('employeeProfile');
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

    private function exchange(
        string $code,
        string $clientId = 'bid',
        string $callback = self::CALLBACK,
    ) {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::READER_TOKEN])
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
