<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Integration;

use App\Enums\AccountStatus;
use App\Http\Middleware\EnsureCityEmailReview;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CityEmailVerificationService;
use App\Services\Identity\MemberBootstrapSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Sentry\Event;
use Sentry\State\Scope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalHubIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const FILES = 'https://files.mbfdhub.com';

    private string $pin;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set('app.url', 'https://www.mbfdhub.com');
            $app['config']->set('session.domain', '.mbfdhub.com');
            $app['config']->set('session.secure', true);
        });
        $this->traitsUsedByTest = array_fill_keys(array_keys(class_uses_recursive(self::class)), 1);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->pin = bin2hex(random_bytes(8));
        config()->set('policy-library.pin_hash', Hash::make($this->pin));
        config()->set('policy-library.pin_ttl_minutes', 5);
        Permission::findOrCreate('files.manage', 'web');
        Permission::findOrCreate('admin.access', 'web');
    }

    public function test_guest_library_assets_and_management_fail_closed_and_login_stays_on_files_domain(): void
    {
        $this->get(self::FILES.'/')->assertRedirect(self::FILES.'/login');
        $this->getJson(self::FILES.'/api/manuals')->assertUnauthorized();
        $this->getJson(self::FILES.'/assets/00000000-0000-4000-8000-000000000000')->assertUnauthorized();
        $this->getJson(self::FILES.'/manage/manuals')->assertUnauthorized();
        $this->get(self::FILES.'/login')->assertOk()->assertSee('employee_id', false);
    }

    public function test_real_canonical_login_registers_identity_then_requires_the_independent_pin(): void
    {
        $password = bin2hex(random_bytes(18));
        $user = $this->linkedUser($password);
        $this->get(self::FILES.'/')->assertRedirect();
        $this->from(self::FILES.'/login')->post(self::FILES.'/login', [
            'employee_id' => $user->employee_id, 'password' => $password,
        ])->assertRedirect(self::FILES.'/access');
        $this->assertAuthenticatedAs($user, 'web');
        self::assertNotNull(session('auth.canonical_session_id'));
        $this->assertDatabaseHas('authentication_sessions', ['user_id' => $user->id]);
        $this->carryCurrentSession();
        self::assertFalse((bool) session('auth.city_email_review_required'));
        $this->getJson(self::FILES.'/api/manuals')->assertForbidden();
        $this->post(self::FILES.'/access', ['pin' => $this->pin])->assertRedirect();
        $this->carryCurrentSession();
        $this->getJson(self::FILES.'/api/manuals')->assertOk()->assertJsonPath('can_manage', false);
    }

    public function test_markerless_real_hub_user_cannot_enter_even_with_a_valid_pin_grant(): void
    {
        $user = $this->linkedUser();
        parent::actingAs($user, 'web');
        $this->withSession(['policy-library.access' => $this->grant($user)]);
        $this->getJson(self::FILES.'/api/manuals')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_canonical_revocation_overrides_pin_and_admin_permission(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $this->withSession(['policy-library.access' => $this->grant($user)]);
        $registeredId = session('auth.canonical_session_id');
        AuthenticationSession::query()->whereKey($registeredId)->update(['revoked_at' => now()]);
        $this->getJson(self::FILES.'/api/manuals')->assertUnauthorized();
    }

    public function test_member_with_pin_is_denied_management_and_legacy_admin_role_is_not_an_entitlement(): void
    {
        $user = $this->linkedUser();
        Role::findOrCreate('admin', 'web');
        $user->assignRole('admin');
        $this->actingAsCanonicalUser($user);
        $this->withSession(['policy-library.access' => $this->grant($user)]);
        $this->getJson(self::FILES.'/api/manuals')->assertOk()->assertJsonPath('can_manage', false);
        $this->get(self::FILES.'/manage/manuals')->assertForbidden();
    }

    public function test_dedicated_web_permission_allows_existing_member_to_manage_without_modifying_hub_panel_access(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        self::assertFalse($user->hasCurrentAdminPanelEntitlement());
        $this->actingAsCanonicalUser($user);
        $this->get(self::FILES.'/manage/manuals')->assertOk();
    }

    public function test_management_root_resolves_to_an_authorized_management_page(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $this->get(self::FILES.'/manage')->assertOk()->assertSee('Manual Builder');
    }

    public function test_current_hub_admin_entitlement_automatically_allows_library_management(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('admin.access');
        $this->actingAsCanonicalUser($user);
        $this->get(self::FILES.'/manage/manuals')->assertOk();
    }

    public function test_bootstrap_session_cannot_reach_pin_or_library_routes(): void
    {
        $this->withSession([MemberBootstrapSession::KEY => ['invalid_restricted_context' => true]]);
        $this->getJson(self::FILES.'/api/manuals')->assertUnauthorized();
    }

    public function test_valid_restricted_member_bootstrap_cannot_reach_library_or_management(): void
    {
        $user = $this->linkedUser();
        $user->employeeProfile->forceFill(['roster_status' => 'active'])->save();
        $user->forceFill([
            'account_status' => AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => true,
        ])->save();
        $user->givePermissionTo('files.manage');
        self::assertTrue($user->isBootstrapOnboardingPending());
        $this->withSession([MemberBootstrapSession::KEY => [
            'user_id' => $user->id, 'employee_profile_id' => $user->employee_profile_id,
            'invitation_id' => 1, 'security_version' => $user->security_version,
            'issued_at' => now()->timestamp, 'expires_at' => now()->addMinutes(5)->timestamp,
            'binding' => bin2hex(random_bytes(32)),
        ]]);
        $this->getJson(self::FILES.'/api/manuals')->assertForbidden()->assertJsonPath('code', 'bootstrap_onboarding_required');
        $this->getJson(self::FILES.'/manage/manuals')->assertForbidden();
    }

    public function test_pin_expires_without_revoking_the_existing_hub_session(): void
    {
        $user = $this->linkedUser();
        $this->actingAsCanonicalUser($user);
        $registryId = session('auth.canonical_session_id');
        $this->withSession(['policy-library.access' => $this->grant($user, now()->timestamp - 1)]);
        $this->getJson(self::FILES.'/api/manuals')->assertForbidden();
        $this->assertAuthenticatedAs($user, 'web');
        self::assertSame($registryId, session('auth.canonical_session_id'));
    }

    public function test_module_routes_do_not_exist_on_the_normal_hub_domain(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $this->get('https://www.mbfdhub.com/manage/manuals')->assertNotFound();
        $this->get('https://www.mbfdhub.com/access')->assertNotFound();
    }

    public function test_real_library_livewire_snapshot_rechecks_required_password_change(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $snapshot = $this->managementSnapshot();
        $user->forceFill(['must_change_password' => true])->save();
        $this->postJson(self::FILES.'/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertRedirect(self::FILES.'/employee/set-password');
    }

    public function test_real_library_livewire_snapshot_rechecks_permission_revocation(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $snapshot = $this->managementSnapshot();
        $user->revokePermissionTo('files.manage');
        Auth::guard('web')->setUser($user->fresh());
        $this->postJson(self::FILES.'/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertForbidden();
    }

    public function test_real_library_livewire_snapshot_rechecks_canonical_session_revocation(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $snapshot = $this->managementSnapshot();
        AuthenticationSession::query()->whereKey(session('auth.canonical_session_id'))->update(['revoked_at' => now()]);
        $this->postJson(self::FILES.'/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertUnauthorized();
    }

    public function test_livewire_without_json_accept_header_returns_session_expiry_instead_of_login_html(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $snapshot = $this->managementSnapshot();
        AuthenticationSession::query()->whereKey(session('auth.canonical_session_id'))->update(['revoked_at' => now()]);
        $this->call('POST', self::FILES.'/livewire/update', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true',
        ], json_encode(['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]], JSON_THROW_ON_ERROR))
            ->assertUnauthorized()->assertJsonPath('code', 'auth_session_expired');
    }

    public function test_unlinked_legacy_admin_livewire_uses_existing_hub_password_recovery_page(): void
    {
        $user = User::factory()->create([
            'employee_profile_id' => null, 'account_status' => AccountStatus::Active,
            'must_change_password' => false,
        ]);
        $user->givePermissionTo('admin.access');
        $this->actingAsCanonicalUser($user);
        $snapshot = $this->managementSnapshot();
        $user->forceFill(['must_change_password' => true])->save();
        $this->postJson(self::FILES.'/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [], 'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertRedirect(self::FILES.'/admin/set-password');
    }

    public function test_real_library_livewire_snapshot_rechecks_city_email_review(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $snapshot = $this->managementSnapshot();
        $user->employeeProfile->forceFill(['city_email' => null])->save();
        $user->forceFill(['email' => 'policy-integration@example.invalid'])->save();
        Auth::guard('web')->setUser($user->fresh());
        self::assertTrue(app(CityEmailVerificationService::class)->requiresReview($user));
        $this->withSession([EnsureCityEmailReview::SESSION_KEY => true]);
        session()->save();
        $this->carryCurrentSession();
        $this->postJson(self::FILES.'/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertRedirect(self::FILES.'/account/city-email');
    }

    public function test_real_library_livewire_update_succeeds_for_current_authorized_account(): void
    {
        $user = $this->linkedUser();
        $user->givePermissionTo('files.manage');
        $this->actingAsCanonicalUser($user);
        $snapshot = $this->managementSnapshot();
        $this->postJson(self::FILES.'/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [], 'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk()->assertJsonCount(1, 'components');
    }

    public function test_actual_sentry_scope_scrubs_pin_body_and_query_after_request_capture(): void
    {
        foreach ([['pin' => 'generated-integration-value'], 'pin=generated-integration-value'] as $body) {
            $event = Event::createEvent()->setRequest([
                'url' => self::FILES.'/access?pin=generated-integration-value',
                'query_string' => 'pin=generated-integration-value', 'data' => $body, 'method' => 'POST',
            ]);
            \Sentry\configureScope(static function (Scope $scope) use ($event): void {
                $result = $scope->applyToEvent($event);
                self::assertNotNull($result);
                self::assertArrayNotHasKey('data', $result->getRequest());
                self::assertArrayNotHasKey('query_string', $result->getRequest());
                self::assertSame(self::FILES.'/access', $result->getRequest()['url']);
                self::assertSame('POST', $result->getRequest()['method']);
            });
        }
    }

    public function test_policy_redactor_preserves_existing_hub_sentry_request_handling(): void
    {
        $request = ['url' => 'https://www.mbfdhub.com/access', 'data' => ['fixture' => 'safe'], 'method' => 'POST'];
        $event = Event::createEvent()->setRequest($request);
        \Sentry\configureScope(static function (Scope $scope) use ($event, $request): void {
            self::assertSame($request, $scope->applyToEvent($event)->getRequest());
        });
    }

    public function test_sentry_scrubs_pin_on_path_variants_the_actual_hub_router_accepts(): void
    {
        $this->actingAsCanonicalUser($this->linkedUser());
        foreach (['/access/', '/acce%73s'] as $path) {
            $this->post(self::FILES.$path, ['pin' => 'generated-wrong-fixture-pin'])
                ->assertRedirect()->assertSessionHasErrors('pin');
            $event = Event::createEvent()->setRequest([
                'url' => self::FILES.$path, 'data' => ['pin' => 'generated-wrong-fixture-pin'], 'method' => 'POST',
            ]);
            \Sentry\configureScope(static function (Scope $scope) use ($event): void {
                self::assertArrayNotHasKey('data', $scope->applyToEvent($event)->getRequest());
            });
        }
    }

    public function test_module_queue_and_upload_caps_preserve_the_existing_hub_connection(): void
    {
        self::assertSame(51200, config('policy-library.max_upload_kb'));
        self::assertSame(['required', 'file', 'max:51200'], config('livewire.temporary_file_upload.rules'));
        self::assertSame(90, config('queue.connections.redis.retry_after'));
        self::assertSame(1860, config('queue.connections.policy-library.retry_after'));
        self::assertSame(config('queue.connections.redis.connection'), config('queue.connections.policy-library.connection'));
        self::assertSame('policy-library', config('queue.connections.policy-library.queue'));
        self::assertLessThan(config('queue.connections.policy-library.retry_after'), config('policy-library.job_timeout'));
    }

    private function managementSnapshot(): string
    {
        $page = $this->get(self::FILES.'/manage/manuals/create')->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        foreach ($matches[1] as $value) {
            $snapshot = html_entity_decode($value, ENT_QUOTES);
            if (str_contains((string) data_get(json_decode($snapshot, true), 'memo.name'), 'create-manual')) {
                return $snapshot;
            }
        }
        self::fail('The real management form did not emit its signed Livewire snapshot.');
    }

    private function linkedUser(?string $password = null): User
    {
        $cityEmail = 'policy.integration.'.bin2hex(random_bytes(8)).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => 'POLICY-'.bin2hex(random_bytes(4)),
            'name' => 'Policy Integration Fixture', 'rank' => 'Firefighter',
            'password' => bin2hex(random_bytes(24)), 'must_change_password' => false,
            'city_email' => $cityEmail,
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active,
            'email' => $cityEmail,
            'password' => Hash::make($password ?? bin2hex(random_bytes(24))),
            'must_change_password' => false,
        ])->load('employeeProfile');
    }

    private function grant(User $user, ?int $expires = null): array
    {
        return [
            'user_id' => (string) $user->id, 'expires_at' => $expires ?? now()->addMinutes(5)->timestamp,
            'pin_version' => hash('sha256', (string) config('policy-library.pin_hash')),
        ];
    }

    private function carryCurrentSession(): void
    {
        $this->withCookie((string) config('session.cookie'), session()->getId())->withCredentials();
    }
}
