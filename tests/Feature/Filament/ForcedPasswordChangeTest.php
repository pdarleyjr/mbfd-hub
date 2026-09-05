<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Filament\Pages\SetPasswordPage;
use App\Http\Middleware\EnsureCanonicalSessionIsCurrent;
use App\Http\Middleware\ForcePasswordChange;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\Training\TrainingTodo;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function panelRoutes(): array
    {
        return [
            'admin' => ['admin', '/admin', '/admin/set-password', 'admin'],
            'training' => ['training_admin', '/training', '/training/set-password', 'training'],
            'workgroups' => ['workgroup_member', '/workgroups', '/workgroups/set-password', 'workgroups'],
        ];
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_are_redirected_to_their_current_panel_password_page(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->grantPanelAccess($user, $role);

        $this->actingAs($user)
            ->get($homeUrl)
            ->assertRedirect($setPasswordUrl);

        $this->actingAs($user)
            ->get($setPasswordUrl)
            ->assertOk()
            ->assertSee('Password Change Required');

        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
    }

    #[DataProvider('panelRoutes')]
    public function test_unflagged_users_keep_access_to_their_current_panel(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => false]);
        $this->grantPanelAccess($user, $role);

        $this->actingAs($user)
            ->get($homeUrl)
            ->assertOk();

        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
        self::assertNotSame($setPasswordUrl, $homeUrl);
    }

    public function test_password_update_revokes_old_sessions_rotates_the_current_session_and_preserves_canonical_login(): void
    {
        Http::fake();
        config([
            'services.screentinker.sync_url' => 'https://screen-sync.invalid/api/users',
            'services.screentinker.sync_token' => 'test-token',
        ]);

        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'must_change_password' => true,
            'password' => Hash::make('current-password'),
            'password_changed_at' => null,
            'security_version' => 7,
        ]);
        $employee = $this->linkEmployee($user, 'PASSWORD-CHANGE-100', 'employee-password-must-not-change');
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.access', 'web'));

        $this->actingAsCanonicalUser($user);
        $this->bindCanonicalSessionToLivewireTestRequests();
        $currentSessionIdBefore = $this->app['session.store']->getId();
        $currentRegistryIdBefore = session('auth.canonical_session_id');
        $employeePasswordBefore = $employee->getRawOriginal('password');
        $issuedAt = CarbonImmutable::now()->subMinute();
        $secondBrowser = app(SessionRegistry::class)->register(
            $user,
            'second-browser-pre-change-session',
            SessionContextClass::UnmanagedBrowser,
            $issuedAt,
            $issuedAt->addHour(),
            $issuedAt->addDay(),
        );
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SetPasswordPage::class)
            ->fillForm([
                'current_password' => 'current-password',
                'password' => 'A-longer-replacement-password-2026',
                'password_confirmation' => 'A-longer-replacement-password-2026',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $user->refresh();

        self::assertFalse($user->must_change_password);
        self::assertTrue(Hash::check('A-longer-replacement-password-2026', $user->password));
        self::assertFalse(Hash::check('current-password', $user->password));
        self::assertSame(8, $user->security_version);
        self::assertNotNull($user->password_changed_at);
        self::assertSame($employeePasswordBefore, $employee->fresh()->getRawOriginal('password'));
        self::assertNotSame($currentSessionIdBefore, $this->app['session.store']->getId());
        self::assertSame($user->password, session('password_hash_web'));

        $preChangeSessions = AuthenticationSession::query()
            ->whereIn('id', [$currentRegistryIdBefore, $secondBrowser->id])
            ->get();
        self::assertCount(2, $preChangeSessions);
        foreach ($preChangeSessions as $preChangeSession) {
            self::assertNotNull($preChangeSession->revoked_at);
            self::assertSame('password changed', $preChangeSession->revoked_reason);
        }

        $replacement = AuthenticationSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->sole();
        self::assertSame($user->security_version, $replacement->security_version);
        self::assertSame($replacement->id, session('auth.canonical_session_id'));
        self::assertSame(
            hash_hmac('sha256', $this->app['session.store']->getId(), (string) config('app.key')),
            $replacement->getRawOriginal('session_id_hash'),
        );
        self::assertTrue(app(SessionRegistry::class)->isCurrent($user, $replacement, CarbonImmutable::now()));
        self::assertIsInt(session((string) config('security.recent_authentication.session_key')));
        $this->get('/admin')->assertOk();

        $secondBrowserRequest = Request::create('/admin', 'GET');
        $secondBrowserSession = $this->app['session']->driver();
        $secondBrowserSession->setId('second-browser-pre-change-session');
        $secondBrowserSession->put('auth.canonical_session_id', $secondBrowser->id);
        $secondBrowserRequest->setLaravelSession($secondBrowserSession);
        $secondBrowserRequest->setUserResolver(static fn (): User => $user->fresh());
        Auth::guard('web')->login($user->fresh());
        $secondBrowserResponse = app(EnsureCanonicalSessionIsCurrent::class)
            ->handle($secondBrowserRequest, static fn () => response('must not be reached'));
        self::assertSame(302, $secondBrowserResponse->getStatusCode());
        self::assertSame(url('/login'), $secondBrowserResponse->headers->get('Location'));

        $this->logoutCanonicalSession();
        $this->from('/login')->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'current-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('employee_id');
        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'A-longer-replacement-password-2026',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'web');
        Http::assertNothingSent();
    }

    public function test_employee_panel_forced_password_change_rotates_to_a_current_canonical_session(): void
    {
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'must_change_password' => true,
            'password' => Hash::make('current-password'),
        ]);
        $this->linkEmployee($user, 'EMPLOYEE-PANEL-100', 'employee-password-must-not-change');
        $this->actingAsCanonicalUser($user);
        $this->bindCanonicalSessionToLivewireTestRequests();
        $oldRegistryId = session('auth.canonical_session_id');
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        $this->get('/employee/set-password')->assertOk();
        Livewire::test(SetPasswordPage::class)
            ->fillForm([
                'current_password' => 'current-password',
                'password' => 'A-longer-replacement-password-2026',
                'password_confirmation' => 'A-longer-replacement-password-2026',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect('/employee/dashboard');

        $user->refresh();
        self::assertFalse($user->must_change_password);
        self::assertNotNull(AuthenticationSession::query()->findOrFail($oldRegistryId)->revoked_at);
        self::assertSame($user->security_version, AuthenticationSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->sole()
            ->security_version);
        $this->withCookie(
            (string) config('session.cookie'),
            $this->app['session.store']->getId(),
        )->withCredentials();
        $employeePanelResponse = $this->get('/employee/dashboard');
        self::assertSame(
            200,
            $employeePanelResponse->getStatusCode(),
            'Unexpected Employee panel redirect: '.(string) $employeePanelResponse->headers->get('Location'),
        );
    }

    public function test_password_update_rejects_an_invalid_current_password(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'password' => Hash::make('current-password'),
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.access', 'web'));

        $this->actingAsCanonicalUser($user);
        $this->bindCanonicalSessionToLivewireTestRequests();
        $beforeUser = (array) DB::table('users')->where('id', $user->id)->first();
        $beforeSessions = AuthenticationSession::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AuthenticationSession $session): array => $session->getRawOriginal())
            ->all();
        $beforeLaravelSessionId = $this->app['session.store']->getId();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SetPasswordPage::class)
            ->fillForm([
                'current_password' => 'incorrect-password',
                'password' => 'A-longer-replacement-password-2026',
                'password_confirmation' => 'A-longer-replacement-password-2026',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $user->refresh();

        self::assertTrue($user->must_change_password);
        self::assertTrue(Hash::check('current-password', $user->password));
        self::assertSame($beforeUser, (array) DB::table('users')->where('id', $user->id)->first());
        self::assertSame($beforeSessions, AuthenticationSession::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AuthenticationSession $session): array => $session->getRawOriginal())
            ->all());
        self::assertSame($beforeLaravelSessionId, $this->app['session.store']->getId());
    }

    public function test_legacy_web_password_middleware_does_not_hijack_a_livewire_update(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($user);

        $request = Request::create('/livewire/update', 'POST');

        $response = app(ForcePasswordChange::class)->handle(
            $request,
            fn () => response('Livewire update allowed'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Livewire update allowed', $response->getContent());
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_cannot_invoke_a_protected_panel_livewire_update(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => false]);
        $this->grantPanelAccess($user, $role);

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($user)->get($homeUrl)->assertOk(),
        );

        $user->forceFill(['must_change_password' => true])->save();

        $response = $this->actingAs($user->fresh())
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [[
                        'path' => '',
                        'method' => '$refresh',
                        'params' => [],
                    ]],
                ]],
            ]);

        $response->assertRedirect($setPasswordUrl);
        self::assertTrue($user->fresh()->must_change_password);
        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_can_complete_password_change_through_livewire_and_regain_panel_access(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create([
            'must_change_password' => true,
            'password' => Hash::make('current-password'),
        ]);
        $this->grantPanelAccess($user, $role);

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($user)->get($setPasswordUrl)->assertOk(),
            'set-password-page',
        );

        $response = $this->actingAs($user)
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'data.current_password' => 'current-password',
                        'data.password' => 'A-longer-replacement-password-2026',
                        'data.password_confirmation' => 'A-longer-replacement-password-2026',
                    ],
                    'calls' => [[
                        'path' => '',
                        'method' => 'save',
                        'params' => [],
                    ]],
                ]],
            ]);

        $response->assertOk();
        self::assertFalse($user->fresh()->must_change_password);
        self::assertTrue(Hash::check('A-longer-replacement-password-2026', $user->fresh()->password));
        $this->actingAs($user->fresh())->get($homeUrl)->assertOk();
        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_can_log_out_without_completing_password_change(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->grantPanelAccess($user, $role);

        $this->actingAs($user)
            ->post(Filament::getPanel($panelId)->getLogoutUrl())
            ->assertRedirect();

        $this->assertGuest('web');
    }

    public function test_livewire_reapplies_the_admin_training_redirect_after_a_role_change(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.access', 'web'));

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($user)->get('/admin')->assertOk(),
        );

        $user->syncRoles([Role::findOrCreate('training_admin', 'web')]);
        $user->revokePermissionTo('admin.access');

        $this->actingAs($user->fresh())
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ])
            ->assertRedirect('/training');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function restrictedPanelRoles(): array
    {
        return [
            'training' => ['training_admin', '/training'],
            'workgroups' => ['workgroup_member', '/workgroups'],
        ];
    }

    #[DataProvider('restrictedPanelRoles')]
    public function test_livewire_rechecks_training_and_workgroup_panel_access_after_a_role_is_removed(
        string $role,
        string $homeUrl,
    ): void {
        $this->withoutVite();

        $user = User::factory()->create(['must_change_password' => false]);
        $this->grantPanelAccess($user, $role);

        if ($role === 'training_admin') {
            TrainingTodo::create([
                'title' => 'Restricted livewire training todo',
                'is_completed' => false,
                'created_by' => $user->id,
            ]);
        }

        $panelResponse = $this->actingAs($user)->get($homeUrl)->assertOk();

        $snapshot = $this->livewireSnapshotFrom(
            $panelResponse,
            $role === 'training_admin' ? 'training-stats-widget' : null,
        );

        if ($role === 'workgroup_member') {
            WorkgroupMember::query()
                ->where('user_id', $user->id)
                ->update(['is_active' => false]);
        } else {
            $user->syncRoles([]);
        }

        $this->actingAs($user->fresh())
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ])
            ->assertNotFound();
    }

    private function livewireSnapshotFrom(TestResponse $response, ?string $componentNameSuffix = null): string
    {
        $matched = preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);

        self::assertGreaterThan(0, $matched, 'Expected a real Livewire snapshot in the panel response.');

        $componentNames = [];

        foreach ($matches[1] as $encodedSnapshot) {
            $snapshot = html_entity_decode($encodedSnapshot, ENT_QUOTES);
            $componentName = data_get(json_decode($snapshot, true), 'memo.name');
            $componentNames[] = (string) $componentName;

            if ($componentNameSuffix === null || str_ends_with((string) $componentName, $componentNameSuffix)) {
                return $snapshot;
            }
        }

        self::fail(sprintf(
            'Expected a Livewire component ending in [%s]; found [%s].',
            $componentNameSuffix,
            implode(', ', array_unique($componentNames)),
        ));
    }

    private function grantPanelAccess(User $user, string $role): void
    {
        $user->assignRole(Role::findOrCreate($role, 'web'));

        if ($role === 'admin') {
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.access', 'web'));
        }

        if ($role !== 'workgroup_member') {
            return;
        }

        $workgroup = Workgroup::create([
            'name' => 'Forced password test workgroup '.$user->id,
            'created_by' => $user->id,
        ]);

        WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
        ]);
    }

    private function linkEmployee(User $user, string $employeeId, string $password): Employee
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Forced Password Test Employee',
            'rank' => 'Firefighter',
            'password' => Hash::make($password),
            'must_change_password' => false,
        ]);
        $user->forceFill([
            'employee_id' => $employeeId,
            'employee_profile_id' => $employee->id,
        ])->save();

        return $employee;
    }
}
