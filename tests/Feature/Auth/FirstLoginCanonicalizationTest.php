<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FirstLoginCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_employee_is_created_just_in_time_then_uses_normal_login(): void
    {
        $employee = $this->employee('JIT-100');
        $employeeHash = $employee->getRawOriginal('password');
        $before = User::query()->count();

        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-secret',
        ])->assertRedirect('/activate-account');

        $nonce = $this->activationNonce();
        $this->post('/activate-account', [
            'nonce' => $nonce,
            'path' => 'no_existing_user',
            'no_legacy_account_assertion' => '1',
        ])->assertRedirect('/');

        $this->assertSame($before + 1, User::query()->count());
        $user = User::query()->where('employee_profile_id', $employee->id)->sole();
        $this->assertSame("employee-{$employee->id}@canonical.mbfdhub.invalid", $user->email);
        $this->assertSame($employee->employee_id, $user->employee_id);
        $this->assertSame($employeeHash, $user->getRawOriginal('password'));
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertCount(0, $user->roles);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('authentication_sessions', 1);
        $this->get('/daily/stations')->assertOk();

        $userId = $user->id;
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-secret',
        ])->assertRedirect('/');
        $this->assertSame($before + 1, User::query()->count());
        $this->assertAuthenticatedAs(User::query()->findOrFail($userId), 'web');
    }

    public function test_privileged_employee_claims_existing_user_and_preserves_authorization_relationships(): void
    {
        $employee = $this->employee('JIT-200');
        $user = User::factory()->create([
            'email' => 'legacy-admin@example.test',
            'employee_id' => null,
            'employee_profile_id' => null,
            'account_status' => AccountStatus::PendingActivation,
            'password' => Hash::make('legacy-secret'),
        ]);
        $role = Role::findOrCreate('admin', 'web');
        $permission = Permission::findOrCreate('view_any_user', 'web');
        $user->assignRole($role);
        $user->givePermissionTo($permission);
        $workgroup = Workgroup::query()->create(['name' => 'Recovery Workgroup', 'created_by' => $user->id]);
        WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
        ]);
        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'Tests\\Fixtures\\CanonicalRecoveryNotification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pushSubscription = $user->pushSubscriptions()->create([
            'endpoint' => 'https://push.example.test/canonical-recovery',
            'public_key' => 'test-public-key',
            'auth_token' => 'test-auth-token',
            'content_encoding' => 'aesgcm',
        ]);
        $staleSessionId = (string) Str::uuid();
        DB::table('authentication_sessions')->insert([
            'id' => $staleSessionId,
            'user_id' => $user->id,
            'session_id_hash' => hash('sha256', 'pre-canonical-claim-session'),
            'security_version' => $user->security_version,
            'context_class' => 'unmanaged_browser',
            'issued_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHour(),
            'absolute_expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staleSession = AuthenticationSession::query()->findOrFail($staleSessionId);
        $userId = $user->id;
        $securityVersion = $user->security_version;

        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-secret',
        ])->assertRedirect('/activate-account');
        $this->post('/activate-account', [
            'nonce' => $this->activationNonce(),
            'path' => 'existing_user',
            'legacy_email' => $user->email,
            'legacy_password' => 'legacy-secret',
        ])->assertRedirect('/');

        $user->refresh();
        $this->assertSame($userId, $user->id);
        $this->assertSame($employee->id, $user->employee_profile_id);
        $this->assertSame($employee->employee_id, $user->employee_id);
        $this->assertSame($employee->getRawOriginal('password'), $user->getRawOriginal('password'));
        $this->assertSame(AccountStatus::Active, $user->account_status);
        $this->assertSame($securityVersion + 1, $user->security_version);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasDirectPermission('view_any_user'));
        $this->assertDatabaseHas('workgroup_members', ['workgroup_id' => $workgroup->id, 'user_id' => $userId]);
        $this->assertDatabaseHas('notifications', ['id' => $notificationId, 'notifiable_id' => $userId]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $pushSubscription->id, 'subscribable_id' => $userId]);
        $this->assertNotNull($staleSession->fresh()->revoked_at);
        $this->assertSame(1, User::query()->count());
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));

        $this->post('/logout');
        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-secret',
        ])->assertRedirect('/');
        $this->assertSame(1, User::query()->count());
        $this->assertSame($securityVersion + 1, $user->fresh()->security_version);
    }

    public function test_bad_legacy_claim_fails_closed_and_rotates_the_nonce_without_consuming_the_intent(): void
    {
        $employee = $this->employee('JIT-300');
        $user = $this->legacyAdmin('legacy-bad@example.test');
        $before = (array) DB::table('users')->where('id', $user->id)->first();
        $throttleKey = 'canonical-first-login:'.hash_hmac(
            'sha256',
            $employee->id.'|'.$user->email.'|127.0.0.1',
            (string) config('app.key'),
        );

        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-secret',
        ])->assertRedirect('/activate-account');
        $nonce = $this->activationNonce();
        $this->from('/activate-account')->post('/activate-account', [
            'nonce' => $nonce,
            'path' => 'existing_user',
            'legacy_email' => $user->email,
            'legacy_password' => 'wrong-secret',
        ])->assertRedirect('/activate-account')
            ->assertSessionHasErrors(['legacy_email' => 'The provided credentials are invalid.']);

        $this->assertSame($before, (array) DB::table('users')->where('id', $user->id)->first());
        $this->assertNull($employee->fresh()->user);
        $this->assertGuest('web');
        $this->assertNotSame($nonce, $this->activationNonce());
        $this->assertSame(1, RateLimiter::attempts($throttleKey));
    }

    public function test_service_identity_cannot_be_claimed_as_a_legacy_human_user(): void
    {
        $employee = $this->employee('JIT-400');
        $user = $this->legacyAdmin('froc-service@example.test');
        $user->forceFill(['employee_id' => 'FROC-TEST-RECOVERY'])->save();

        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-secret',
        ])->assertRedirect('/activate-account');
        $this->from('/activate-account')->post('/activate-account', [
            'nonce' => $this->activationNonce(),
            'path' => 'existing_user',
            'legacy_email' => $user->email,
            'legacy_password' => 'legacy-secret',
        ])->assertSessionHasErrors(['legacy_email' => 'The provided credentials are invalid.']);

        $this->assertNull($user->fresh()->employee_profile_id);
        $this->assertNull($employee->fresh()->user);
    }

    public function test_activation_intent_expires_and_cannot_be_replayed(): void
    {
        $employee = $this->employee('JIT-500');
        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'employee-secret',
        ])->assertRedirect('/activate-account');
        $nonce = $this->activationNonce();

        $this->travel(11)->minutes();
        $this->post('/activate-account', [
            'nonce' => $nonce,
            'path' => 'no_existing_user',
            'no_legacy_account_assertion' => '1',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(session()->has('auth.canonical_activation_intent'));
        $this->assertGuest('web');
    }

    private function activationNonce(): string
    {
        $response = $this->get('/activate-account')->assertOk();
        $nonce = $response->viewData('nonce');
        $this->assertIsString($nonce);
        $this->assertNotSame('', $nonce);

        return $nonce;
    }

    private function employee(string $employeeId): Employee
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'First Login Employee',
            'rank' => 'Firefighter',
            'password' => 'employee-secret',
            'must_change_password' => false,
        ]);
        $this->assertTrue(Hash::check('employee-secret', $employee->getAuthPassword()));

        return $employee;
    }

    private function legacyAdmin(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'employee_id' => null,
            'employee_profile_id' => null,
            'account_status' => AccountStatus::PendingActivation,
            'password' => Hash::make('legacy-secret'),
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $user;
    }
}
