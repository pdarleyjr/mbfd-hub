<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\EmployeeProfileEvent;
use App\Models\User;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\EmployeeProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_transition_synchronizes_raw_profile_for_every_linking_caller(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => '61001', 'name' => 'Authoritative Name', 'rank' => 'Captain',
            'station' => 'Station 2', 'phone' => '305-555-0101', 'display_name' => 'Captain Example',
            'password' => 'employee-fixture-password',
        ]);
        $user = User::factory()->create(['employee_id' => $employee->employee_id, 'name' => 'Old Account Name', 'rank' => 'Old Rank']);
        $hash = $user->getRawOriginal('password');
        $service = app(\App\Services\Identity\AccountSecurityService::class);
        $result = $service->completeCanonicalLink($user, $employee->id, $employee->employee_id, null, now(), activatePending: false);
        self::assertTrue($result['changed']);
        foreach (Employee::PROFILE_FIELDS as $field) {
            self::assertSame($employee->getAttribute($field), DB::table('users')->where('id', $user->id)->value($field));
        }
        self::assertSame($hash, $user->fresh()->getRawOriginal('password'));
        self::assertSame($employee->id, $user->fresh()->employee_profile_id);
        self::assertFalse($service->completeCanonicalLink($user, $employee->id, $employee->employee_id, null, now(), activatePending: false)['changed']);
    }

    public function test_manager_updates_authoritative_profile_and_compatibility_without_changing_identity(): void
    {
        [$employee, $user] = $this->member();
        $before = $user->getRawOriginal();
        $employeeHash = $employee->getRawOriginal('password');
        $manager = $this->manager();

        $updated = app(EmployeeProfileService::class)->update($manager, $employee, [
            'name' => 'Correct Personnel Name', 'rank' => 'Captain',
            'station' => 'Station 2 / A Shift', 'phone' => '+1 305 555 0199', 'display_name' => 'Preferred Name',
        ]);

        foreach (['name', 'rank', 'station', 'phone', 'display_name'] as $field) {
            self::assertSame($updated->$field, $user->fresh()->getRawOriginal($field));
        }
        foreach (['id', 'employee_id', 'employee_profile_id', 'password', 'account_status', 'security_version', 'email'] as $field) {
            self::assertSame($before[$field], $user->fresh()->getRawOriginal($field));
        }
        self::assertSame($employeeHash, $updated->getRawOriginal('password'));
        $event = EmployeeProfileEvent::query()->sole();
        self::assertSame($manager->id, $event->actor_user_id);
        self::assertSame($employee->id, $event->employee_id);
        self::assertSame('profile_updated', $event->action);
        self::assertSame(['name', 'rank', 'station', 'phone', 'display_name'], $event->metadata['fields']);
        self::assertStringNotContainsString('305', json_encode($event->metadata));
    }

    public function test_self_can_change_only_personal_contact_and_display_name(): void
    {
        [$employee, $user] = $this->member();
        app(EmployeeProfileService::class)->update($user, $employee, ['phone' => '305-555-0100', 'display_name' => 'Member']);
        self::assertSame('Member', $employee->fresh()->display_name);
        self::assertSame('305-555-0100', $user->fresh()->phone);
        $this->expectException(AuthorizationException::class);
        app(EmployeeProfileService::class)->update($user, $employee, ['name' => 'Different Identity']);
    }

    public function test_self_cannot_change_connected_email_and_other_members_cannot_edit(): void
    {
        [$employee, $user] = $this->member();
        try {
            app(EmployeeProfileService::class)->update($user, $employee, ['city_email' => 'new@miamibeachfl.gov']);
            self::fail('Self email edits must use mailbox confirmation.');
        } catch (AuthorizationException) {
            self::assertNull($employee->fresh()->city_email);
        }
        $this->expectException(AuthorizationException::class);
        app(EmployeeProfileService::class)->update(User::factory()->create(['account_status' => 'active']), $employee, ['phone' => '123']);
    }

    public function test_stale_manager_permissions_or_disabled_actor_do_not_authorize(): void
    {
        [$employee] = $this->member();
        $manager = $this->manager();
        $manager->load('permissions');
        DB::table('model_has_permissions')->where('model_id', $manager->id)->delete();
        $this->expectException(AuthorizationException::class);
        app(EmployeeProfileService::class)->update($manager, $employee, ['rank' => 'Chief']);
    }

    public function test_disabled_self_cannot_edit_profile(): void
    {
        [$employee, $user] = $this->member();
        DB::table('users')->where('id', $user->id)->update(['account_status' => 'disabled']);
        $this->expectException(AuthorizationException::class);
        app(EmployeeProfileService::class)->update($user, $employee, ['phone' => '123']);
    }

    public function test_security_fields_and_invalid_profile_fields_reject_entire_update(): void
    {
        [$employee] = $this->member();
        foreach (['employee_id', 'employee_profile_id', 'password', 'roles', 'roster_status', 'account_status', 'email'] as $forbidden) {
            try {
                app(EmployeeProfileService::class)->update($this->manager(), $employee, ['name' => 'Must Not Save', $forbidden => 'x']);
                self::fail('Unexpected profile field was accepted.');
            } catch (ValidationException) {
                self::assertSame('Roster Name', $employee->fresh()->name);
            }
        }
        $this->expectException(ValidationException::class);
        app(EmployeeProfileService::class)->update($this->manager(), $employee, ['name' => ' ']);
    }

    public function test_admin_city_email_is_atomic_and_never_grants_mailbox_proof(): void
    {
        [$employee, $user] = $this->member();
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
        app(EmployeeProfileService::class)->update($this->administrator(), $employee, ['name' => 'Updated', 'city_email' => 'City.Member@miamibeachfl.gov'], 'password', 'Approved personnel correction');
        self::assertSame('city.member@miamibeachfl.gov', $employee->fresh()->city_email);
        self::assertSame('city.member@miamibeachfl.gov', $user->fresh()->email);
        self::assertNull($user->fresh()->email_verified_at);
        User::factory()->create(['email' => 'collision@miamibeachfl.gov']);
        try {
            app(EmployeeProfileService::class)->update($this->administrator(), $employee, ['name' => 'Must Roll Back', 'city_email' => 'collision@miamibeachfl.gov'], 'password', 'Approved personnel correction');
            self::fail('Collision must reject entire save.');
        } catch (ValidationException) {
            self::assertSame('Updated', $employee->fresh()->name);
            self::assertSame('city.member@miamibeachfl.gov', $user->fresh()->email);
        }
    }

    public function test_roster_only_employee_can_be_updated_without_creating_login(): void
    {
        $employee = $this->employee();
        app(EmployeeProfileService::class)->update($this->administrator(), $employee, ['rank' => 'Captain', 'city_email' => 'roster.only@miamibeachfl.gov'], 'password', 'Approved personnel correction');
        self::assertSame('Captain', $employee->fresh()->rank);
        self::assertFalse($employee->user()->exists());
    }

    public function test_direct_model_writes_never_make_linked_user_profile_authoritative(): void
    {
        [$employee, $user] = $this->member();
        $user->forceFill(['name' => 'Forged', 'rank' => 'Chief', 'phone' => 'forged'])->save();
        self::assertSame('Roster Name', $user->fresh()->name);
        self::assertSame('Firefighter', $user->fresh()->rank);
        self::assertNull($user->fresh()->phone);
        $employee->update(['name' => 'New Roster Name', 'rank' => 'Captain', 'phone' => '123']);
        self::assertSame('New Roster Name', $user->fresh()->getRawOriginal('name'));
        self::assertSame('123', $user->fresh()->getRawOriginal('phone'));
    }

    public function test_city_email_changes_require_fresh_actor_password_and_roll_back_profile(): void
    {
        [$employee] = $this->member();
        $actor = $this->administrator();
        DB::table('users')->where('id', $actor->id)->update(['password' => bcrypt('changed-password')]);
        foreach ([null, 'password', 'incorrect'] as $password) {
            try {
                app(EmployeeProfileService::class)->update($actor, $employee, ['name' => 'Not Saved', 'city_email' => 'sensitive@miamibeachfl.gov'], $password, 'Approved personnel correction');
                self::fail('A fresh current password is required for recovery identity changes.');
            } catch (CurrentPasswordMismatch) {
                self::assertSame('Roster Name', $employee->fresh()->name);
                self::assertNull($employee->fresh()->city_email);
            }
        }
        app(EmployeeProfileService::class)->update($actor, $employee, ['city_email' => 'sensitive@miamibeachfl.gov'], 'changed-password', 'Approved personnel correction');
        self::assertSame('sensitive@miamibeachfl.gov', $employee->fresh()->city_email);
    }

    public function test_even_super_admin_self_cannot_edit_personnel_identity_fields(): void
    {
        [$employee, $actor] = $this->member();
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        app(EmployeeProfileService::class)->update($actor, $employee, ['display_name' => 'Preferred']);
        $this->expectException(AuthorizationException::class);
        app(EmployeeProfileService::class)->update($actor, $employee, ['rank' => 'Chief']);
    }

    public function test_city_email_change_requires_reason_and_revokes_sessions_and_both_recovery_addresses(): void
    {
        [$employee, $user] = $this->member();
        $administrator = $this->administrator();
        foreach ([null, ' ', str_repeat('x', 501)] as $reason) {
            try {
                app(EmployeeProfileService::class)->update($administrator, $employee, ['city_email' => 'confirmed@miamibeachfl.gov'], 'password', $reason);
                self::fail('Email changes require an audit reason.');
            } catch (ValidationException) {
                self::assertNull($employee->fresh()->city_email);
            }
        }
        $session = AuthenticationSession::factory()->create(['user_id' => $user->id]);
        foreach ([$user->email, 'confirmed@miamibeachfl.gov'] as $email) {
            DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => bcrypt('old-token'), 'created_at' => now()]);
        }
        $version = $user->security_version;
        app(EmployeeProfileService::class)->update($administrator, $employee, ['city_email' => 'confirmed@miamibeachfl.gov'], 'password', 'Approved correction');
        self::assertNotNull($session->fresh()->revoked_at);
        self::assertSame($version + 1, $user->fresh()->security_version);
        self::assertSame(0, DB::table('password_reset_tokens')->count());
        self::assertSame('Approved correction', EmployeeProfileEvent::query()->where('result', 'success')->sole()->reason);
    }

    public function test_personnel_manager_cannot_redirect_any_recovery_address(): void
    {
        [$employee] = $this->member();
        $this->expectException(AuthorizationException::class);
        app(EmployeeProfileService::class)->update($this->manager(), $employee, ['city_email' => 'attacker@miamibeachfl.gov'], 'password', 'Attempted takeover');
    }

    #[DataProvider('rejectedCityEmailAttempts')]
    public function test_rejected_city_email_attempt_is_audited_without_mutating_identity_or_logging_values(string $scenario): void
    {
        [$employee, $target] = $this->member();
        $actor = $this->administrator();
        $email = 'attempted-private-address@miamibeachfl.gov';
        $password = $scenario === 'wrong_password' ? 'secret-wrong-password' : 'password';
        $reason = $scenario === 'invalid_reason' ? str_repeat('R', 501) : 'Approved correction';
        if ($scenario === 'stale_actor') {
            DB::table('users')->where('id', $actor->id)->update(['account_status' => 'disabled']);
        }
        if ($scenario === 'collision') {
            User::factory()->create(['email' => $email]);
        }
        $beforeUser = $target->fresh()->getRawOriginal();
        $beforeEmployee = $employee->fresh()->getRawOriginal();
        $attributes = ['name' => 'Must not persist', 'city_email' => $email];
        if ($scenario === 'forbidden_field') {
            $attributes['password'] = 'new-credential-must-not-log';
        }
        try {
            app(EmployeeProfileService::class)->update($actor, $employee, $attributes, $password, $reason);
            self::fail('The sensitive email attempt should be rejected.');
        } catch (AuthorizationException|ValidationException) {
            self::assertSame($beforeUser, $target->fresh()->getRawOriginal());
            self::assertSame($beforeEmployee, $employee->fresh()->getRawOriginal());
            $event = EmployeeProfileEvent::query()->sole();
            self::assertSame('denied', $event->result);
            self::assertSame('profile_updated', $event->action);
            self::assertSame($actor->id, $event->actor_user_id);
            self::assertSame($target->id, $event->target_user_id);
            self::assertSame($employee->id, $event->employee_id);
            self::assertSame(['fields' => ['name', 'city_email']], $event->metadata);
            self::assertSame(mb_substr($reason, 0, 500), $event->reason);
            self::assertStringNotContainsString($email, $event->toJson());
            self::assertStringNotContainsString('secret-wrong-password', $event->toJson());
            self::assertStringNotContainsString('new-credential-must-not-log', $event->toJson());
            self::assertStringNotContainsString('Must not persist', $event->toJson());
            self::assertSame(0, EmployeeProfileEvent::query()->whereIn('result', ['allowed', 'success'])->count());
        }
    }

    public static function rejectedCityEmailAttempts(): array
    {
        return array_map(fn (string $scenario): array => [$scenario], ['wrong_password', 'stale_actor', 'collision', 'invalid_reason', 'forbidden_field']);
    }

    public function test_rejected_ordinary_profile_validation_does_not_add_sensitive_email_audits(): void
    {
        [$employee] = $this->member();
        try {
            app(EmployeeProfileService::class)->update($this->administrator(), $employee, ['name' => ' ']);
            self::fail('The existing name validation must reject blank names.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('name', $exception->errors());
            self::assertSame(0, EmployeeProfileEvent::query()->count());
            self::assertSame('Roster Name', $employee->fresh()->name);
        }
    }

    public function test_user_hard_delete_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->expectException(\LogicException::class);
        $user->delete();
    }

    public function test_loaded_relation_is_authoritative_without_per_field_or_per_user_queries(): void
    {
        [$employee, $user] = $this->member();
        DB::table('users')->where('id', $user->id)->update(['name' => 'Legacy Drift']);
        $loaded = User::query()->with('employeeProfile')->findOrFail($user->id);
        DB::enableQueryLog();
        self::assertSame($employee->name, $loaded->name);
        self::assertSame($employee->rank, $loaded->rank);
        self::assertNull($loaded->phone);
        self::assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_employee_hard_delete_is_rejected_even_without_login(): void
    {
        $employee = $this->employee();
        $this->expectException(\LogicException::class);
        $employee->delete();
    }

    public function test_stale_user_profile_save_cannot_overwrite_a_newer_personnel_edit(): void
    {
        [$employee, $staleUser] = $this->member();
        app(EmployeeProfileService::class)->update($this->manager(), $employee, ['name' => 'New Personnel Name']);
        $staleUser->name = 'Attempted Old Profile Edit';
        $staleUser->phone = 'attempted';
        $staleUser->save();
        self::assertSame('New Personnel Name', $staleUser->fresh()->getRawOriginal('name'));
        self::assertNull($staleUser->fresh()->getRawOriginal('phone'));
    }

    public function test_partial_eager_loaded_employee_does_not_erase_unselected_profile_fields(): void
    {
        [, $user] = $this->member();
        $loaded = User::query()->with('employeeProfile:id,employee_id')->findOrFail($user->id);
        self::assertSame('Roster Name', $loaded->name);
        self::assertSame('Firefighter', $loaded->rank);
    }

    public function test_canonical_provisioning_copies_all_authoritative_profile_fields(): void
    {
        $employee = $this->employee();
        $employee->update(['display_name' => 'Preferred', 'phone' => '123', 'station' => 'Station 2']);
        $result = app(CanonicalUserProvisioner::class)->create($employee->id, 'MISSING_OR_UNSUPPORTED', now());
        foreach (Employee::PROFILE_FIELDS as $field) {
            self::assertSame($employee->$field, $result['user']->getRawOriginal($field));
        }
        self::assertSame('pending_activation', $result['user']->getRawOriginal('account_status'));
    }

    public function test_migration_backfills_only_new_fields_and_repairs_user_name_rank_without_credentials(): void
    {
        [$employee, $user] = $this->member();
        $migration = require database_path('migrations/2026_09_08_190000_add_authoritative_employee_profile.php');
        $migration->down();
        DB::table('users')->where('id', $user->id)->update(['name' => 'Old Login Name', 'rank' => 'Old Rank', 'station' => 'Station 3', 'phone' => '123', 'display_name' => 'Preferred']);
        $hash = $user->getRawOriginal('password');
        $migration->up();
        self::assertSame('Station 3', $employee->fresh()->station);
        self::assertSame('123', $employee->fresh()->phone);
        self::assertSame('Preferred', $employee->fresh()->display_name);
        self::assertSame('Roster Name', $user->fresh()->getRawOriginal('name'));
        self::assertSame('Firefighter', $user->fresh()->getRawOriginal('rank'));
        self::assertSame($hash, $user->fresh()->getRawOriginal('password'));
    }

    private function employee(): Employee
    {
        return Employee::query()->create(['employee_id' => (string) random_int(100000, 999999), 'name' => 'Roster Name', 'rank' => 'Firefighter', 'password' => 'password']);
    }

    /** @return array{Employee, User} */
    private function member(): array
    {
        $employee = $this->employee();

        return [$employee, User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => 'active'])];
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['account_status' => 'active']);
        $manager->givePermissionTo(Permission::findOrCreate('admin.personnel.manage', 'web'));

        return $manager;
    }

    private function administrator(): User
    {
        $administrator = $this->manager();
        $administrator->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $administrator;
    }
}
