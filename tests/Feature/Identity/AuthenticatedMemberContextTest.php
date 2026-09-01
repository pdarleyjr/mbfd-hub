<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Identity\SessionRegistry;
use App\Support\Workgroups\WorkgroupContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuthenticatedMemberContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertUnauthorized();
    }

    public function test_active_linked_user_receives_minimal_server_derived_context(): void
    {
        $employee = $this->employee('20731');
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => $employee->id,
            'email' => 'context-secret@example.test',
            'password' => 'not-for-the-api',
            'remember_token' => 'remember-token-not-for-the-api',
        ]);
        $permission = Permission::findOrCreate('daily.inspection.create', 'web');
        $user->givePermissionTo($permission);
        Role::findOrCreate('logistics_admin', 'web');
        $user->assignRole('logistics_admin');

        $workgroup = Workgroup::query()->create([
            'name' => 'Hose Evaluation',
            'description' => 'D02 test workgroup',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'facilitator',
            'is_active' => true,
        ]);

        $rawSessionId = 'rawsessionidmustneverleavetheserver00000';
        $this->authenticateWithRegisteredSession($user, $rawSessionId, [
            WorkgroupContext::SESSION_KEY => $workgroup->id,
        ]);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $response = $this->getJson(
            '/api/me/context?actor_id=999999&user_id=999999&employee_id=999999',
            $this->sameOriginHeaders(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('identity.user_id', $user->id)
            ->assertJsonPath('identity.has_personnel_profile', true)
            ->assertJsonPath('personnel.employee_profile_id', $employee->id)
            ->assertJsonPath('personnel.employee_number', '20731')
            ->assertJsonPath('personnel.name', 'Identity Test Employee')
            ->assertJsonPath('personnel.rank', 'Firefighter')
            ->assertJsonPath('authorization.abilities.0', 'daily.inspection.create')
            ->assertJsonPath('authorization.workgroups.0.id', $workgroup->id)
            ->assertJsonPath('authorization.workgroups.0.name', 'Hose Evaluation')
            ->assertJsonPath('authorization.workgroups.0.membership_role', 'facilitator')
            ->assertJsonPath('authorization.active_workgroup.id', $workgroup->id)
            ->assertJsonPath('operational_context.station', null)
            ->assertJsonPath('operational_context.apparatus', null)
            ->assertJsonPath('operational_context.room', null)
            ->assertJsonPath('operational_context.shift', null)
            ->assertJsonPath('operational_context.device', null)
            ->assertJsonPath('session.authenticated', true)
            ->assertJsonMissingPath('identity.email')
            ->assertJsonMissingPath('identity.roles')
            ->assertJsonMissingPath('identity.account_status')
            ->assertJsonMissingPath('identity.security_version')
            ->assertJsonMissingPath('session.id')
            ->assertJsonMissingPath('session.security_version');

        $content = $response->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString($rawSessionId, $content);
        self::assertStringNotContainsString('not-for-the-api', $content);
        self::assertStringNotContainsString('remember-token-not-for-the-api', $content);
        self::assertStringNotContainsString('context-secret@example.test', $content);
        self::assertStringNotContainsString('logistics_admin', $content);
        self::assertStringNotContainsString('999999', $content);
        self::assertLessThanOrEqual(12, $queries, 'The bootstrap context exceeded its query budget.');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('Cookie', (string) $response->headers->get('Vary'));

        $context = app(AuthenticatedMemberContextResolver::class)->resolve($this->app['request']);
        self::assertSame($user->id, $context->actor()->userId());
        self::assertTrue($context->actor()->employee()?->is($employee));
        self::assertSame(
            $context,
            app(AuthenticatedMemberContextResolver::class)->resolve($this->app['request']),
        );
        $context->requireAbility('daily.inspection.create');
    }

    public function test_active_unlinked_user_receives_canonical_only_context_without_employee_inference(): void
    {
        $this->employee('10010');
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => '10010',
            'employee_profile_id' => null,
        ]);
        $this->authenticateWithRegisteredSession($user);

        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertOk()
            ->assertJsonPath('identity.user_id', $user->id)
            ->assertJsonPath('identity.has_personnel_profile', false)
            ->assertJsonPath('personnel', null);

        self::assertNull($user->fresh()->employee_profile_id);

        $context = app(AuthenticatedMemberContextResolver::class)->resolve($this->app['request']);
        self::assertSame($user->id, $context->actor()->userId());
        self::assertNull($context->actor()->employee());

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $context->actor()->requireEmployee();
    }

    public function test_pending_or_disabled_account_cannot_receive_context(): void
    {
        foreach ([AccountStatus::PendingActivation, AccountStatus::Disabled] as $status) {
            $user = User::factory()->create(['account_status' => AccountStatus::Active]);
            $this->authenticateWithRegisteredSession($user, sha1($status->value));
            $user->forceFill(['account_status' => $status])->save();

            $this->getJson('/api/me/context', $this->sameOriginHeaders())
                ->assertUnauthorized();

            $this->resetHttpSessionState();
        }
    }

    public function test_stale_security_version_cannot_receive_context(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->authenticateWithRegisteredSession($user);
        $user->increment('security_version');

        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertUnauthorized();
    }

    public function test_revoked_session_cannot_receive_context(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $session = $this->authenticateWithRegisteredSession($user);
        $session->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => 'test revocation',
        ])->save();

        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertUnauthorized();
    }

    public function test_expired_session_cannot_receive_context(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $session = $this->authenticateWithRegisteredSession($user);
        $session->forceFill(['idle_expires_at' => now()->subMinute()])->save();

        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertUnauthorized();
    }

    public function test_permission_loss_is_reflected_without_reusing_stale_authorization_data(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $permission = Permission::findOrCreate('daily.inspection.create', 'web');
        $user->givePermissionTo($permission);
        $this->authenticateWithRegisteredSession($user);

        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertOk()
            ->assertJsonPath('authorization.abilities.0', 'daily.inspection.create');

        $user->revokePermissionTo($permission);
        $user->unsetRelation('permissions');
        $user->unsetRelation('roles');

        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertOk()
            ->assertJsonPath('authorization.abilities', []);
    }

    public function test_inactive_workgroup_membership_is_not_exposed_as_identity_or_context(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $workgroup = Workgroup::query()->create([
            'name' => 'Inactive Workgroup',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => false,
        ]);
        $this->authenticateWithRegisteredSession($user, sessionData: [
            WorkgroupContext::SESSION_KEY => $workgroup->id,
        ]);

        $this->getJson('/api/me/context', $this->sameOriginHeaders())
            ->assertOk()
            ->assertJsonPath('authorization.workgroups', [])
            ->assertJsonPath('authorization.active_workgroup', null);
    }

    public function test_cross_origin_request_cannot_reuse_the_first_party_session_cookie(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->authenticateWithPersistedCookie($user);

        $this->getJson('/api/me/context', [
            'Origin' => 'https://attacker.example',
            'Referer' => 'https://attacker.example/',
        ])->assertUnauthorized();
    }

    public function test_stateful_api_keeps_csrf_protection_for_mutations(): void
    {
        $apiMiddleware = $this->app['router']->getMiddlewareGroups()['api'];

        self::assertContains(EnsureFrontendRequestsAreStateful::class, $apiMiddleware);
        self::assertSame(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            config('sanctum.middleware.validate_csrf_token'),
        );

        $sameOrigin = \Illuminate\Http\Request::create('/api/me/context', 'POST');
        $sameOrigin->headers->set('Origin', 'http://localhost');
        $sameOrigin->headers->set('Referer', 'http://localhost/');

        self::assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($sameOrigin));

        $crossOrigin = \Illuminate\Http\Request::create('/api/me/context', 'POST');
        $crossOrigin->headers->set('Origin', 'https://attacker.example');
        self::assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($crossOrigin));
    }

    /** @param array<string, mixed> $sessionData */
    private function authenticateWithRegisteredSession(
        User $user,
        string $rawSessionId = 'dddddddddddddddddddddddddddddddddddddddd',
        array $sessionData = [],
    ): AuthenticationSession {
        $sessionStore = $this->app['session']->driver();
        $sessionStore->setId($rawSessionId);
        $this->withSession($sessionData);
        $this->actingAs($user, 'web');
        $this->withCookie((string) config('session.cookie'), $rawSessionId);
        $this->withCredentials();

        $registeredAt = CarbonImmutable::now();
        $registered = app(SessionRegistry::class)->register(
            $user,
            $rawSessionId,
            SessionContextClass::UnmanagedBrowser,
            $registeredAt,
            $registeredAt->addHour(),
            $registeredAt->addDay(),
        );

        return $registered;
    }

    private function authenticateWithPersistedCookie(User $user): void
    {
        $rawSessionId = 'cccccccccccccccccccccccccccccccccccccccc';
        $sessionStore = $this->app['session']->driver();
        $sessionStore->setId($rawSessionId);
        $sessionStore->start();
        $guard = Auth::guard('web');
        self::assertInstanceOf(SessionGuard::class, $guard);
        $sessionStore->put($guard->getName(), $user->getAuthIdentifier());
        $sessionStore->save();

        $this->withCookie((string) config('session.cookie'), $rawSessionId);
        $this->withCredentials();

        $registeredAt = CarbonImmutable::now();
        app(SessionRegistry::class)->register(
            $user,
            $rawSessionId,
            SessionContextClass::UnmanagedBrowser,
            $registeredAt,
            $registeredAt->addHour(),
            $registeredAt->addDay(),
        );

        $sessionStore->setId(Str::random(40));
        Auth::forgetGuards();
    }

    private function resetHttpSessionState(): void
    {
        $this->flushSession();
        Auth::forgetGuards();
        $this->defaultCookies = [];
    }

    /** @return array<string, string> */
    private function sameOriginHeaders(): array
    {
        return [
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ];
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Identity Test Employee',
            'rank' => 'Firefighter',
            'password' => 'employee-password-not-for-the-api',
            'must_change_password' => false,
        ]);
    }
}
