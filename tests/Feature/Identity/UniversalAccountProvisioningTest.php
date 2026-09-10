<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Services\Identity\UniversalAccountInventory;
use App\Services\Security\EmployeeAccountAdministration;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UniversalAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_inventory_uses_exact_identifiers_and_separates_active_departed_and_service_records(): void
    {
        $canonicalEmployee = $this->employee('UA-100');
        $canonical = User::factory()->create([
            'employee_profile_id' => $canonicalEmployee->id,
            'employee_id' => $canonicalEmployee->employee_id,
            'account_status' => AccountStatus::Active,
        ]);
        $rosterOnly = $this->employee('UA-101');
        $departed = $this->employee('UA-102', 'departed');
        $service = User::factory()->create(['employee_id' => null, 'employee_profile_id' => null]);

        $before = User::query()->count();
        self::assertSame(Command::SUCCESS, Artisan::call('identity:provision-universal-accounts', ['--format' => 'json']));
        self::assertSame($before, User::query()->count());

        $report = app(UniversalAccountInventory::class)->report();
        self::assertSame(2, $report['summary']['active_employees']);
        self::assertSame(1, $report['summary']['EXISTING_CANONICAL_USER']);
        self::assertSame(1, $report['summary']['ROSTER_ONLY_NEEDS_USER']);
        self::assertSame(1, $report['summary']['DEPARTED']);
        self::assertSame(1, $report['summary']['NONEMPLOYEE/SERVICE']);
        self::assertSame(1, $report['summary']['active_employees_without_canonical_user']);
        self::assertSame($canonical->id, collect($report['rows'])->firstWhere('employee_id', 'UA-100')['canonical_user_id']);
        self::assertNull(collect($report['rows'])->firstWhere('employee_id', $rosterOnly->employee_id)['canonical_user_id']);
        self::assertNull(collect($report['rows'])->firstWhere('employee_id', $departed->employee_id)['canonical_user_id']);
        self::assertSame($service->id, collect($report['rows'])->firstWhere('classification', 'NONEMPLOYEE/SERVICE')['canonical_user_id']);
    }

    public function test_apply_preserves_existing_identity_and_credentials_links_exact_legacy_and_preprovisions_roster_only_idempotently(): void
    {
        $member = Role::findOrCreate('member', 'web');
        $admin = Role::findOrCreate('admin', 'web');
        $permission = Permission::findOrCreate('app.bid.access', 'web');

        $canonicalEmployee = $this->employee('UA-200');
        $canonical = User::factory()->create([
            'employee_profile_id' => $canonicalEmployee->id,
            'employee_id' => $canonicalEmployee->employee_id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('existing-password-2026'),
            'must_change_password' => false,
        ]);
        $canonical->assignRole($admin);
        $canonical->givePermissionTo($permission);
        UserNotificationSubscription::query()->create([
            'user_id' => $canonical->id,
            'event_key' => User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES,
            'database_enabled' => false,
            'webpush_enabled' => false,
            'email_enabled' => true,
        ]);
        $canonicalBefore = $canonical->fresh()->only(['id', 'password', 'account_status', 'must_change_password']);
        $subscriptionBefore = UserNotificationSubscription::query()->where('user_id', $canonical->id)->firstOrFail()->getRawOriginal();

        $legacyEmployee = $this->employee('UA-201');
        $legacy = User::factory()->create([
            'employee_id' => $legacyEmployee->employee_id,
            'employee_profile_id' => null,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('legacy-password-2026'),
            'must_change_password' => false,
        ]);
        $legacy->assignRole($admin);
        $legacyBefore = $legacy->fresh()->only(['id', 'password', 'account_status', 'must_change_password']);

        $newEmployee = $this->employee('UA-202');
        $departed = $this->employee('UA-203', 'departed');

        self::assertSame(Command::SUCCESS, Artisan::call('identity:provision-universal-accounts', [
            '--apply' => true,
            '--confirm' => 'PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS',
            '--format' => 'json',
        ]), Artisan::output());

        self::assertSame($canonicalBefore, $canonical->fresh()->only(array_keys($canonicalBefore)));
        self::assertTrue($canonical->fresh()->hasAllRoles([$admin, $member]));
        self::assertTrue($canonical->fresh()->hasDirectPermission($permission));
        self::assertSame($subscriptionBefore, UserNotificationSubscription::query()->where('user_id', $canonical->id)->firstOrFail()->getRawOriginal());
        self::assertSame($legacyBefore, $legacy->fresh()->only(array_keys($legacyBefore)));
        self::assertSame($legacyEmployee->id, $legacy->fresh()->employee_profile_id);
        self::assertTrue($legacy->fresh()->hasAllRoles([$admin, $member]));

        $created = $newEmployee->user()->sole();
        self::assertSame(AccountStatus::PendingActivation, $created->account_status);
        self::assertTrue($created->must_change_password);
        self::assertTrue($created->hasRole('member'));
        self::assertFalse(Hash::check((string) $newEmployee->getAuthPassword(), $created->getAuthPassword()));
        self::assertNull($departed->user);
        $createdBefore = $created->fresh()->only(['id', 'password', 'account_status', 'must_change_password', 'security_version']);

        self::assertSame(Command::SUCCESS, Artisan::call('identity:provision-universal-accounts', [
            '--apply' => true,
            '--confirm' => 'PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS',
            '--format' => 'json',
        ]), Artisan::output());
        self::assertSame($createdBefore, $created->fresh()->only(array_keys($createdBefore)));
        self::assertSame(3, User::query()->count());
        $after = app(UniversalAccountInventory::class)->report();
        self::assertSame(0, $after['summary']['active_employees_without_canonical_user']);
        self::assertSame(3, $after['summary']['EXISTING_CANONICAL_USER']);
    }

    public function test_apply_refuses_conflicting_exact_links_without_mutation(): void
    {
        $first = $this->employee('UA-300');
        $second = $this->employee('UA-301');
        $conflicting = User::factory()->create([
            'employee_profile_id' => $second->id,
            'employee_id' => $first->employee_id,
            'account_status' => AccountStatus::Active,
        ]);
        $before = $conflicting->fresh()->getRawOriginal();

        self::assertSame(Command::FAILURE, Artisan::call('identity:provision-universal-accounts', [
            '--apply' => true,
            '--confirm' => 'PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS',
            '--format' => 'json',
        ]));
        self::assertSame($before, $conflicting->fresh()->getRawOriginal());
        self::assertNull($first->fresh()->user);
        $report = app(UniversalAccountInventory::class)->report();
        self::assertGreaterThan(0, $report['summary']['identity_conflicts']);
    }

    public function test_admin_issued_temporary_password_blocks_hub_api_and_federation_until_password_change(): void
    {
        $actor = User::factory()->create(['account_status' => AccountStatus::Active, 'password' => 'admin-password']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $employee = $this->employee('UA-400');
        self::assertSame(Command::SUCCESS, Artisan::call('identity:provision-universal-accounts', [
            '--apply' => true,
            '--confirm' => 'PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS',
        ]));
        $pending = $employee->user()->sole();
        $issued = app(EmployeeAccountAdministration::class)->createForEmployee(
            $actor, $employee, 'controlled-temporary-password-2026', 'admin-password', 'Controlled live-flow rehearsal',
        );
        self::assertSame($pending->id, $issued->id);

        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'controlled-temporary-password-2026',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($issued, 'web');
        $this->withCookie((string) config('session.cookie'), session()->getId());
        foreach (['/', '/employee', '/auth/bid/authorize', '/auth/media-control/authorize'] as $path) {
            $this->get($path)->assertRedirect('/employee/set-password');
        }
        $this->get('/employee/set-password')->assertOk()->assertSee('Password Change Required');
        $this->getJson('/api/me/context')->assertUnauthorized();
    }

    private function employee(string $employeeId, string $status = 'active'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Universal Account Test',
            'roster_status' => $status,
            'password' => 'unusable-test-roster-password',
        ]);
    }
}
