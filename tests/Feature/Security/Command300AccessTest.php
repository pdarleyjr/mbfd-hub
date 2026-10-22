<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\OidcSession;
use App\Models\User;
use App\Services\Oidc\OidcIdentityPolicy;
use App\Services\Security\ApplicationAccessService;
use App\Services\Security\Command300Access;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use League\OAuth2\Server\Exception\OAuthServerException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Command300AccessTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate(Command300Access::PERMISSION, 'web');
    }

    public function test_each_alternative_qualification_grants_access_on_its_own(): void
    {
        $access = app(Command300Access::class);

        self::assertSame(Command300Access::SOURCE_CAPTAIN, $access->source($this->member('Captain')));
        self::assertSame(Command300Access::SOURCE_DIVISION_CHIEF, $access->source($this->member('Division Chief')));

        $superAdmin = $this->member('Firefighter');
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        self::assertSame(Command300Access::SOURCE_ADMINISTRATOR, $access->source($superAdmin->fresh()));

        $administrator = $this->member('Lieutenant');
        $administrator->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        self::assertSame(Command300Access::SOURCE_ADMINISTRATOR, $access->source($administrator->fresh()));

        $granted = $this->member('Firefighter');
        $granted->givePermissionTo(Command300Access::PERMISSION);
        self::assertSame(Command300Access::SOURCE_GRANT, $access->source($granted->fresh()));

        $assigned = $this->member('Lieutenant');
        $this->assignment($assigned, '300');
        self::assertSame(Command300Access::SOURCE_ASSIGNMENT, $access->source($assigned->fresh()));
    }

    public function test_ordinary_ranks_and_lookalike_titles_are_denied(): void
    {
        $access = app(Command300Access::class);
        foreach (['Lieutenant', 'Firefighter', 'Driver Engineer', 'Chief Plans Analyst #3', 'Deputy Fire Chief', 'Fire Chief', 'Capt', 'DC', '', 'Captain 5'] as $rank) {
            self::assertFalse($access->allows($this->member($rank)), $rank.' must not qualify');
        }
        self::assertFalse($access->allows(null));
    }

    public function test_rank_matching_tolerates_case_and_spacing_only(): void
    {
        $access = app(Command300Access::class);
        self::assertTrue($access->allows($this->member('  captain ')));
        self::assertTrue($access->allows($this->member('DIVISION   CHIEF')));
    }

    public function test_role_inherited_admin_role_without_panel_entitlement_does_not_qualify(): void
    {
        $member = $this->member('Firefighter');
        $member->assignRole(Role::findOrCreate('admin', 'web'));

        self::assertFalse($member->fresh()->hasCurrentAdminPanelEntitlement());
        self::assertFalse(app(Command300Access::class)->allows($member->fresh()));
    }

    public function test_assignment_must_be_current_verified_exact_300_in_the_latest_bid_year(): void
    {
        $access = app(Command300Access::class);

        $captainFive = $this->member('Firefighter');
        $this->assignment($captainFive, 'Captain 5');
        self::assertFalse($access->allows($captainFive), 'An apparatus named Captain 5 is not a rank');

        $historic = $this->member('Lieutenant');
        $this->assignment($historic, '300', ['superseded_at' => now()]);
        $this->assignment($historic, 'Engine 2');
        self::assertFalse($access->allows($historic), 'A superseded 300 row never qualifies');

        $oldYear = $this->member('Lieutenant');
        $this->assignment($oldYear, '300', ['bid_year' => 2025, 'term_label' => '2025–2026']);
        $this->assignment($this->member('Firefighter'), 'Engine 4', ['bid_year' => 2026]);
        self::assertFalse($access->allows($oldYear), 'Only the latest published bid year counts');

        $unverified = $this->member('Lieutenant');
        $this->assignment($unverified, '300', ['payload_hash' => str_repeat('z', 64)]);
        self::assertFalse($access->allows($unverified), 'Integrity hashes are required');
    }

    public function test_inactive_accounts_and_pending_password_setup_are_denied_even_for_captains(): void
    {
        $access = app(Command300Access::class);
        $disabled = $this->member('Captain');
        User::query()->whereKey($disabled->id)->update(['account_status' => 'disabled']);
        self::assertFalse($access->allows($disabled->fresh()));

        $pending = $this->member('Captain');
        User::query()->whereKey($pending->id)->update(['must_change_password' => true]);
        self::assertFalse($access->allows($pending->fresh()));

        $departed = $this->member('Captain');
        Employee::query()->whereKey($departed->employee_profile_id)->update(['roster_status' => 'departed']);
        self::assertFalse($access->allows($departed->fresh()));
    }

    public function test_rank_change_and_grant_revocation_take_effect_on_the_next_check(): void
    {
        $access = app(Command300Access::class);
        $member = $this->member('Captain');
        self::assertTrue($access->allows($member->fresh()));

        Employee::query()->whereKey($member->employee_profile_id)->update(['rank' => 'Lieutenant']);
        self::assertFalse($access->allows($member->fresh()));

        $member->givePermissionTo(Command300Access::PERMISSION);
        self::assertTrue($access->allows($member->fresh()));
        $member->revokePermissionTo(Command300Access::PERMISSION);
        self::assertFalse($access->allows($member->fresh()));
    }

    public function test_unlinked_administrator_qualifies_without_a_personnel_record(): void
    {
        $administrator = User::factory()->create(['account_status' => 'active', 'employee_profile_id' => null, 'employee_id' => null]);
        $administrator->assignRole(Role::findOrCreate('super_admin', 'web'));

        self::assertSame(Command300Access::SOURCE_ADMINISTRATOR, app(Command300Access::class)->source($administrator->fresh()));
        $unlinkedMember = User::factory()->create(['account_status' => 'active', 'employee_profile_id' => null, 'employee_id' => null]);
        self::assertFalse(app(Command300Access::class)->allows($unlinkedMember));
    }

    public function test_administrator_arm_matches_the_admin_panel_entitlement_contract(): void
    {
        $plain = $this->member('Firefighter');
        $direct = $this->member('Firefighter');
        $direct->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $viaRole = $this->member('Firefighter');
        $role = Role::findOrCreate('fleet_operations_manager', 'web');
        $role->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $viaRole->assignRole($role);
        $super = $this->member('Firefighter');
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));

        foreach ([$plain, $direct, $viaRole, $super] as $user) {
            $user = $user->fresh();
            self::assertSame($user->hasCurrentAdminPanelEntitlement(),
                app(Command300Access::class)->automaticSource($user) === Command300Access::SOURCE_ADMINISTRATOR);
        }
    }

    public function test_gate_matches_the_service(): void
    {
        self::assertTrue(Gate::forUser($this->member('Captain'))->allows(Command300Access::GATE));
        self::assertFalse(Gate::forUser($this->member('Firefighter'))->allows(Command300Access::GATE));

        // Holding the permission through a role is not a grant: Spatie's own
        // gate hook must not short-circuit the policy.
        $viaRole = $this->member('Firefighter');
        $role = Role::findOrCreate('command_viewers', 'web');
        $role->givePermissionTo(Command300Access::PERMISSION);
        $viaRole->assignRole($role);
        self::assertFalse(Gate::forUser($viaRole->fresh())->allows(Command300Access::GATE));
        self::assertFalse(app(Command300Access::class)->allows($viaRole->fresh()));
    }

    public function test_profile_route_overview_api_and_member_context_follow_the_policy(): void
    {
        // The SPA shell is a build artifact; provide a stand-in when it is absent.
        $shell = public_path('daily/index.html');
        $created = ! is_file($shell);
        if ($created) {
            @mkdir(dirname($shell), 0777, true);
            file_put_contents($shell, '<html><head></head><body><div id="root"></div></body></html>');
            $this->beforeApplicationDestroyed(fn () => @unlink($shell));
        }
        $this->actingAsCanonicalFixture('E-300-FF', 'Ordinary Member', 'Firefighter');
        $this->get('/daily/command-300')->assertForbidden();
        $this->get('/daily/COMMAND-300/')->assertForbidden();
        $this->getJson('/api/public/command/overview')->assertForbidden();
        $this->getJson('/api/me/context')->assertOk()->assertJsonPath('authorization.capabilities.command_300_access', false);
        $this->get('/daily/stations')->assertOk();

        $this->logoutCanonicalSession();
        $this->actingAsCanonicalFixture('E-300-CAPT', 'Command Captain', 'Captain');
        $this->get('/daily/command-300')->assertOk();
        $this->getJson('/api/public/command/overview')->assertOk();
        $this->getJson('/api/me/context')->assertOk()->assertJsonPath('authorization.capabilities.command_300_access', true);
    }

    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get('/daily/command-300')->assertRedirect();
        $this->getJson('/api/public/command/overview')->assertUnauthorized();
    }

    public function test_toggle_grants_and_revokes_only_the_direct_grant_and_is_audited(): void
    {
        [$actor, $target] = [$this->superAdmin(), $this->member('Firefighter')];
        $target->givePermissionTo(Permission::findOrCreate('training.access', 'web'));
        $service = app(ApplicationAccessService::class);

        $service->setCommand300Access($actor, $target, true, 'Access-test-password!', 'Acting 300 coverage');
        self::assertTrue(app(Command300Access::class)->allows($target->fresh()));
        self::assertFalse($target->fresh()->hasCurrentAdminPanelEntitlement());
        self::assertFalse($target->fresh()->hasDirectWebPermission('admin.access'));
        self::assertTrue($target->fresh()->hasDirectWebPermission('training.access'));
        $this->assertDatabaseHas('security_action_events', ['actor_user_id' => $actor->id, 'target_user_id' => $target->id,
            'action' => 'change_command_300_access', 'result' => 'allowed', 'reason' => 'Acting 300 coverage']);

        $service->setCommand300Access($actor, $target, false, 'Access-test-password!', 'Coverage ended');
        self::assertFalse(app(Command300Access::class)->allows($target->fresh()));
        self::assertTrue($target->fresh()->hasDirectWebPermission('training.access'));
    }

    public function test_turning_the_toggle_off_never_removes_automatic_access(): void
    {
        [$actor, $captain] = [$this->superAdmin(), $this->member('Captain')];
        $service = app(ApplicationAccessService::class);
        $service->setCommand300Access($actor, $captain, true, 'Access-test-password!', 'Redundant grant');
        $service->setCommand300Access($actor, $captain, false, 'Access-test-password!', 'Remove redundant grant');

        self::assertSame(Command300Access::SOURCE_CAPTAIN, app(Command300Access::class)->source($captain->fresh()));
    }

    public function test_non_super_admin_cannot_use_the_toggle(): void
    {
        $actor = $this->member('Captain', 'Access-test-password!');
        $actor->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $target = $this->member('Firefighter');

        $this->expectException(AuthorizationException::class);
        app(ApplicationAccessService::class)->setCommand300Access($actor->fresh(), $target, true, 'Access-test-password!', 'Self-service attempt');
    }

    public function test_revoking_the_only_route_ends_command_board_sessions(): void
    {
        [$actor, $target] = [$this->superAdmin(), $this->member('Firefighter')];
        $target->givePermissionTo(Command300Access::PERMISSION);
        $session = $this->commandBoardSession($target);

        app(ApplicationAccessService::class)->setCommand300Access($actor, $target, false, 'Access-test-password!', 'Coverage ended');

        self::assertNotNull($session->fresh()->revoked_at);
    }

    public function test_command_board_oidc_policy_requires_300_access_and_carries_the_claim(): void
    {
        $clientId = $this->commandBoardClient();
        $policy = app(OidcIdentityPolicy::class);

        try {
            $policy->user((string) $this->member('Firefighter')->id, $clientId);
            self::fail('An ordinary member must not receive a Command Board sign-in.');
        } catch (OAuthServerException) {
            self::assertTrue(true);
        }

        $captain = $this->member('Captain');
        self::assertTrue($policy->user((string) $captain->id, $clientId)->is($captain));
        $claims = $policy->claims($this->commandBoardSession($captain, $clientId));
        self::assertSame('300', $claims['command_access']);
        self::assertSame((string) $captain->employee_id, $claims['employee_id']);

        // A mid-session demotion fails the next userinfo/token check.
        $session = $this->commandBoardSession($captain, $clientId);
        Employee::query()->whereKey($captain->employee_profile_id)->update(['rank' => 'Lieutenant']);
        $this->expectException(OAuthServerException::class);
        $policy->claims($session);
    }

    public function test_unlinked_administrator_signs_in_to_command_board_without_an_employee_claim(): void
    {
        $clientId = $this->commandBoardClient();
        $administrator = User::factory()->create(['account_status' => 'active', 'employee_profile_id' => null, 'employee_id' => null]);
        $administrator->assignRole(Role::findOrCreate('super_admin', 'web'));

        $claims = app(OidcIdentityPolicy::class)->claims($this->commandBoardSession($administrator->fresh(), $clientId));

        self::assertSame('hub-user:'.$administrator->id, $claims['sub']);
        self::assertArrayNotHasKey('employee_id', $claims);
        self::assertSame('300', $claims['command_access']);
    }

    private function member(string $rank, string $password = 'Member-test-password!'): User
    {
        $this->sequence++;
        $employee = Employee::query()->create(['employee_id' => 'E300-'.$this->sequence, 'name' => 'Member '.$this->sequence,
            'rank' => $rank, 'password' => 'not-used-by-tests', 'must_change_password' => false]);

        return User::factory()->create(['password' => $password, 'account_status' => 'active', 'must_change_password' => false,
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['password' => 'Access-test-password!', 'account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    /** @param array<string, mixed> $overrides */
    private function assignment(User $user, string $unit, array $overrides = []): EmployeeBidAssignment
    {
        $this->sequence++;

        return EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $user->employee_profile_id, 'payload_version' => 2, 'bid_year' => 2026,
            'term_label' => '2026–2027', 'bid_session_id' => 'bid-2026', 'position_id' => 'P'.$this->sequence,
            'rank_label' => 'Division Chief', 'shift_label' => 'A Shift', 'station_label' => 'Station 2',
            'unit_label' => $unit, 'position_label' => 'Division Chief', 'bid_selection_label' => $unit,
            'assignment_type' => 'Assigned', 'assignment_source' => 'retained_nonbiddable', 'a_day_label' => 'A-Day',
            'idempotency_key' => 'key-'.$this->sequence, 'payload_hash' => str_repeat('a', 64), 'is_forced' => false,
            'source_sequence' => $this->sequence, 'source_result_hash' => str_repeat('b', 64), 'source_workbook_sha256' => str_repeat('c', 64),
            ...$overrides,
        ]);
    }

    private function commandBoardClient(): string
    {
        $client = \Laravel\Passport\Client::query()->create(['name' => 'Command Board test', 'secret' => 'test-client-secret', 'provider' => 'users',
            'redirect_uris' => ['https://cmd.mbfdhub.com/auth/callback'], 'grant_types' => ['authorization_code'], 'revoked' => false]);
        config(['oidc.clients.cmd' => $client->id]);

        return (string) $client->id;
    }

    private function commandBoardSession(User $user, ?string $clientId = null): OidcSession
    {
        $this->sequence++;

        return OidcSession::query()->create(['id' => bin2hex(random_bytes(32)), 'auth_code_id' => 'code-'.$this->sequence,
            'user_id' => $user->id, 'employee_profile_id' => $user->employee_profile_id, 'security_version' => (int) $user->security_version,
            'client_id' => $clientId ?? $this->commandBoardClient(), 'application' => 'cmd']);
    }
}
