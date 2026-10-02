<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminLookupSessionContinuityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('lookupPaths')]
    public function test_lookup_retains_the_registered_session_and_following_member_context(string $path): void
    {
        $this->assertLookupContinuity($path);
    }

    #[DataProvider('lookupPaths')]
    public function test_explicit_capability_admin_without_super_admin_retains_the_registered_session(string $path): void
    {
        $this->assertLookupContinuity($path, superAdmin: false);
    }

    #[DataProvider('lookupPaths')]
    public function test_normal_web_lookup_without_origin_or_referer_retains_the_registered_session(string $path): void
    {
        $this->assertLookupContinuity($path, sameOrigin: false);
    }

    #[DataProvider('lookupPaths')]
    public function test_lookup_rejects_an_unauthenticated_request(string $path): void
    {
        $this->request($path)->assertUnauthorized();
    }

    #[DataProvider('lookupPaths')]
    public function test_lookup_rejects_a_member_without_the_required_capability(string $path): void
    {
        $this->login(withCapability: false);
        $this->request($path)->assertForbidden();
    }

    #[DataProvider('invalidSessionCases')]
    public function test_lookup_rejects_a_stale_or_revoked_session(string $path, string $failure): void
    {
        [$user, $registered] = $this->login();
        if ($failure === 'stale') {
            DB::table('users')->where('id', $user->id)->increment('security_version');
        } else {
            $registered->forceFill(['revoked_at' => now(), 'revoked_reason' => 'Lookup continuity regression'])->save();
        }

        $this->request($path)->assertUnauthorized();
    }

    #[DataProvider('lookupPaths')]
    public function test_same_origin_lookup_preserves_the_password_hash_session_check(string $path): void
    {
        [$user, $registered] = $this->login();
        $this->memberContext($user);
        self::assertTrue($this->app['session.store']->has('password_hash_web'));
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('changed-synthetic-password')]);
        self::assertSame($registered->security_version, $user->fresh()->security_version);

        $this->request($path)->assertUnauthorized();
    }

    /** @return array<string, array{string}> */
    public static function lookupPaths(): array
    {
        return [
            'stations' => ['/api/admin/lookups/stations'],
            'apparatus' => ['/api/admin/lookups/apparatus'],
            'personnel' => ['/api/admin/lookups/personnel'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function invalidSessionCases(): array
    {
        $cases = [];
        foreach (self::lookupPaths() as $name => [$path]) {
            foreach (['stale', 'revoked'] as $failure) {
                $cases[$name.'-'.$failure] = [$path, $failure];
            }
        }

        return $cases;
    }

    /** @return array{User, AuthenticationSession, string} */
    private function login(bool $withCapability = true, bool $superAdmin = true): array
    {
        $employee = Employee::query()->create([
            'employee_id' => 'LOOKUP-SESSION-TEST',
            'name' => 'Lookup Session Test Employee',
            'rank' => 'Firefighter',
            'city_email' => 'lookup-session-test@miamibeachfl.gov',
            'password' => 'unused-synthetic-legacy-password',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'email' => $employee->city_email,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('synthetic-login-password'),
        ]);
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        if ($withCapability) {
            $user->givePermissionTo(Permission::findOrCreate('admin.system.view', 'web'));
            if ($superAdmin) {
                $user->assignRole(Role::findOrCreate('super_admin', 'web'));
            }
        }
        $response = $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'synthetic-login-password',
        ], $this->sameOriginHeaders())->assertRedirect('/');
        $registered = AuthenticationSession::query()->sole();
        $sessionId = $response->getCookie((string) config('session.cookie'))?->getValue();
        self::assertIsString($sessionId);
        self::assertTrue(hash_equals(
            $registered->getRawOriginal('session_id_hash'),
            hash_hmac('sha256', $sessionId, (string) config('app.key')),
        ));
        $this->carryCookie($response);

        return [$user, $registered, $sessionId];
    }

    private function assertLookupContinuity(string $path, bool $superAdmin = true, bool $sameOrigin = true): void
    {
        [$user, $registered, $sessionId] = $this->login(superAdmin: $superAdmin);
        if (! $superAdmin) {
            self::assertFalse($user->hasRole('super_admin', 'web'));
            self::assertTrue($user->hasDirectWebPermission('admin.access'));
            self::assertTrue($user->hasDirectWebPermission('admin.system.view'));
        }
        $this->memberContext($user);

        $response = $this->request($path, $sameOrigin)->assertOk()->assertJsonStructure(['data', 'meta']);
        if (! $sameOrigin) {
            self::assertFalse($this->app['request']->headers->has('Origin'));
            self::assertFalse($this->app['request']->headers->has('Referer'));
        }
        $returnedSessionId = $response->getCookie((string) config('session.cookie'))?->getValue();
        self::assertTrue($returnedSessionId === $sessionId, 'A lookup replaced the registered Laravel session cookie.');
        self::assertTrue(hash_equals(
            $registered->getRawOriginal('session_id_hash'),
            hash_hmac('sha256', $returnedSessionId, (string) config('app.key')),
        ));
        self::assertTrue(app(SessionRegistry::class)->isCurrent($user->fresh(), $registered->fresh(), CarbonImmutable::now()));
        $this->carryCookie($response);
        $this->memberContext($user);
        $this->assertDatabaseCount('authentication_sessions', 1);
    }

    private function memberContext(User $user): void
    {
        $response = $this->request('/api/me/context')
            ->assertOk()
            ->assertJsonPath('identity.user_id', $user->id)
            ->assertJsonPath('personnel.employee_profile_id', $user->employee_profile_id)
            ->assertJsonPath('session.authenticated', true);
        $this->carryCookie($response);
    }

    private function request(string $path, bool $sameOrigin = true): TestResponse
    {
        Auth::forgetGuards();
        // A fresh HTTP bootstrap starts on web; auth:sanctum changes this in the reused test container.
        Auth::shouldUse('web');

        return $this->getJson($path, $sameOrigin ? $this->sameOriginHeaders() : []);
    }

    private function carryCookie(TestResponse $response): void
    {
        $cookie = $response->getCookie((string) config('session.cookie'), decrypt: false);
        self::assertNotNull($cookie);
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->withCredentials();
    }

    /** @return array<string, string> */
    private function sameOriginHeaders(): array
    {
        return ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/admin'];
    }
}
