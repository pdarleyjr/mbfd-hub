<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\User;
use App\Services\Training\TrainingRosterSubmissionAuthenticator;
use App\Support\ApplicationAccessRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingRosterSubmissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-roster-password!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity.local_login_enabled' => true, 'security.canonical_login.max_attempts' => 3]);
        Permission::findOrCreate('admin.access', 'web');
        // The additive migration provisions the submit toggle without granting it.
        self::assertTrue(Permission::query()->where('name', 'training.rosters.submit')->where('guard_name', 'web')->exists());
    }

    public function test_fresh_credentials_authorize_once_without_login_or_session_changes(): void
    {
        $user = $this->member();
        $user->givePermissionTo(['admin.access', 'training.rosters.submit']);
        $this->withSession(['kiosk_marker' => 'retained']);
        $before = session()->all();
        $attributes = $user->fresh()->getAttributes();
        $service = app(TrainingRosterSubmissionAuthenticator::class);

        self::assertSame($user->id, $service->authenticate(strtoupper($user->email), self::PASSWORD, '192.0.2.10')->id);
        self::assertSame($user->id, $service->authenticate($user->employee_id, self::PASSWORD, '192.0.2.10')->id);
        self::assertTrue(Auth::guard('web')->guest());
        self::assertSame($before, session()->all());
        self::assertSame($attributes, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_direct_admin_and_submission_grants_are_independent_and_revocation_is_immediate(): void
    {
        $user = $this->member();
        self::assertFalse($user->canSubmitTrainingRosters());
        $user->givePermissionTo('training.rosters.submit');
        self::assertFalse($user->canSubmitTrainingRosters());
        $user->givePermissionTo('admin.access');
        self::assertTrue($user->canSubmitTrainingRosters());
        $user->revokePermissionTo('training.rosters.submit');
        self::assertFalse($user->canSubmitTrainingRosters());
        $user->givePermissionTo('training.rosters.submit');
        $user->revokePermissionTo('admin.access');
        self::assertFalse($user->canSubmitTrainingRosters());
    }

    public function test_role_permissions_cannot_replace_either_direct_grant(): void
    {
        $user = $this->member();
        $role = Role::findOrCreate('training_admin', 'web');
        $role->givePermissionTo(['admin.access', 'training.rosters.submit']);
        $user->assignRole($role);
        self::assertFalse($user->canSubmitTrainingRosters());
        $user->givePermissionTo('admin.access');
        self::assertFalse($user->canSubmitTrainingRosters());
        $user->syncPermissions(['training.rosters.submit']);
        self::assertFalse($user->canSubmitTrainingRosters());
    }

    public function test_active_super_administrator_retains_full_access_but_requires_credentials(): void
    {
        $user = $this->member();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        self::assertTrue($user->canSubmitTrainingRosters());
        self::assertSame($user->id, app(TrainingRosterSubmissionAuthenticator::class)->authenticate($user->email, self::PASSWORD, '192.0.2.10')->id);
        $this->assertDenied($user->email, 'wrong');
        $user->forceFill(['account_status' => 'disabled'])->save();
        self::assertFalse($user->canSubmitTrainingRosters());
        $this->assertDenied($user->email, self::PASSWORD);
    }

    /** @return array<string, array{string}> */
    public static function deniedCases(): array
    {
        return array_combine($cases = ['missing', 'wrong_password', 'disabled', 'pending', 'password_change', 'departed', 'mismatched_identity', 'no_admin', 'no_submit', 'local_login_disabled'], array_map(fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('deniedCases')]
    public function test_denials_are_generic_and_do_not_authenticate(string $case): void
    {
        $user = $this->member();
        $user->givePermissionTo(['admin.access', 'training.rosters.submit']);
        match ($case) {
            'disabled' => $user->forceFill(['account_status' => 'disabled'])->save(),
            'pending' => $user->forceFill(['account_status' => 'pending_activation'])->save(),
            'password_change' => $user->forceFill(['must_change_password' => true])->save(),
            'departed' => $user->employeeProfile->forceFill(['roster_status' => 'departed'])->save(),
            'mismatched_identity' => User::query()->whereKey($user->id)->update(['employee_id' => 'DIFFERENT-ID']),
            'no_admin' => $user->revokePermissionTo('admin.access'),
            'no_submit' => $user->revokePermissionTo('training.rosters.submit'),
            'local_login_disabled' => config(['identity.local_login_enabled' => false]),
            default => null,
        };
        $this->assertDenied($case === 'missing' ? 'missing@example.test' : $user->email, $case === 'wrong_password' ? 'wrong' : self::PASSWORD);
    }

    public function test_throttle_uses_existing_login_policy_and_email_case_cannot_bypass_it(): void
    {
        $user = $this->member();
        $user->givePermissionTo(['admin.access', 'training.rosters.submit']);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertDenied($user->email, 'wrong');
        }
        $this->assertDenied(strtoupper($user->email), self::PASSWORD);
        $key = 'canonical-login:'.hash_hmac('sha256', $user->email.'|192.0.2.10', (string) config('app.key'));
        self::assertTrue(RateLimiter::tooManyAttempts($key, 3));
        $this->travel(61)->seconds();
        self::assertSame($user->id, app(TrainingRosterSubmissionAuthenticator::class)->authenticate($user->email, self::PASSWORD, '192.0.2.10')->id);
        self::assertSame(0, RateLimiter::attempts($key));
    }

    public function test_actual_admin_checkbox_grants_and_revokes_only_the_explicit_capability_with_audit(): void
    {
        $actor = $this->member();
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $target = $this->member();
        $target->givePermissionTo('admin.access');
        self::assertArrayHasKey('training.rosters.submit', app(ApplicationAccessRegistry::class)->capabilityOptions());
        $this->actingAs($actor);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::test(EditEmployee::class, ['record' => $target->employee_profile_id]);
        foreach ([true, false] as $enabled) {
            $component->callAction('manageAdministrationCapabilities', [
                'capabilities' => $enabled ? ['training.rosters.submit'] : [],
                'current_password' => self::PASSWORD,
                'reason' => 'Synthetic roster submission permission test',
            ])->assertHasNoActionErrors();
            self::assertSame($enabled, $target->fresh()->canSubmitTrainingRosters());
            self::assertTrue($target->fresh()->hasCurrentAdminPanelEntitlement());
        }
        $this->assertDatabaseHas('security_action_events', ['actor_user_id' => $actor->id, 'target_user_id' => $target->id, 'action' => 'change_admin_capabilities', 'result' => 'allowed']);
    }

    private function assertDenied(string $identifier, string $password): void
    {
        try {
            app(TrainingRosterSubmissionAuthenticator::class)->authenticate($identifier, $password, '192.0.2.10');
            self::fail('Submission credentials must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(['credentials' => ['The provided credentials cannot authorize this submission.']], $exception->errors());
            self::assertTrue(Auth::guard('web')->guest());
        }
    }

    private function member(): User
    {
        $employee = Employee::query()->create(['name' => 'Synthetic roster submitter', 'employee_id' => 'ROSTER-'.fake()->unique()->numerify('######'), 'roster_status' => 'active', 'password' => Hash::make('unused-personnel-credential')]);

        return User::factory()->create(['employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id, 'account_status' => 'active', 'must_change_password' => false, 'password' => self::PASSWORD]);
    }
}
