<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\DualCredentialIdentityClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

#[Group('postgres')]
final class FirstLoginCanonicalizationPostgresShapeTest extends TestCase
{
    public function test_production_shaped_identity_set_claims_or_creates_only_on_demand(): void
    {
        $this->requireDisposablePostgres();
        $baselineUsers = User::query()->count();
        $baselineEmployees = Employee::query()->count();

        $at = now();
        $employeePassword = 'verified-employee-password';
        $employeeHash = Hash::make($employeePassword);
        $legacyPassword = 'legacy-hub-password';
        $legacyHash = Hash::make($legacyPassword);
        $employeeRows = [];
        for ($number = 1; $number <= 236; $number++) {
            $employeeRows[] = [
                'employee_id' => sprintf('REC-P%04d', $number),
                'name' => "Production Shape Employee {$number}",
                'rank' => 'Firefighter',
                'password' => $employeeHash,
                'must_change_password' => false,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }
        $employeeRows[] = [
            'employee_id' => 'REC-FROC',
            'name' => 'Service Identity Fixture',
            'rank' => null,
            'password' => $employeeHash,
            'must_change_password' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ];
        DB::table('employees')->insert($employeeRows);

        $userRows = [];
        for ($number = 1; $number <= 29; $number++) {
            $userRows[] = [
                'name' => "Legacy Privileged User {$number}",
                'email' => "legacy-privileged-{$number}@production-shape.test",
                'password' => $legacyHash,
                'employee_id' => null,
                'employee_profile_id' => null,
                'account_status' => AccountStatus::PendingActivation->value,
                'security_version' => 1,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }
        $userRows[] = [
            'name' => 'FROC Service Identity',
            'email' => 'froc-service@production-shape.test',
            'password' => $legacyHash,
            'employee_id' => 'REC-FROC',
            'employee_profile_id' => null,
            'account_status' => AccountStatus::PendingActivation->value,
            'security_version' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ];
        DB::table('users')->insert($userRows);

        $role = Role::findOrCreate('production-shape-privileged', 'web');
        $legacyUsers = User::query()->where('email', 'like', 'legacy-privileged-%@production-shape.test')->get();
        DB::table('model_has_roles')->insert($legacyUsers->map(fn (User $user): array => [
            'role_id' => $role->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
        ])->all());

        try {
            $fixtureEmployeeIds = Employee::query()->where('employee_id', 'like', 'REC-%')->pluck('id');
            $this->assertCount(237, $fixtureEmployeeIds);
            $this->assertSame($baselineUsers + 30, User::query()->count());
            $this->assertSame($baselineEmployees + 237, Employee::query()->count());
            $this->assertSame(0, User::query()->whereIn('employee_profile_id', $fixtureEmployeeIds)->count());

            $ordinaryEmployee = Employee::query()->where('employee_id', 'REC-P0236')->sole();
            $created = app(CanonicalUserProvisioner::class)->create(
                $ordinaryEmployee->id,
                'LEGACY_HUMAN_BCRYPT_UNCHANGED',
                now(),
            );
            $this->assertTrue($created['created']);
            $this->assertSame($baselineUsers + 31, User::query()->count());
            $this->assertSame($ordinaryEmployee->id, $created['user']->employee_profile_id);
            $this->assertSame($employeeHash, $created['user']->getRawOriginal('password'));
            $this->assertSame(AccountStatus::Active, $created['user']->account_status);
            $this->assertSame(['member'], $created['user']->getRoleNames()->all());

            $privilegedEmployee = Employee::query()->where('employee_id', 'REC-P0001')->sole();
            $legacyUser = User::query()->where('email', 'legacy-privileged-1@production-shape.test')->sole();
            $legacyUserId = $legacyUser->id;
            $claimed = app(DualCredentialIdentityClaim::class)->claim(
                $privilegedEmployee->id,
                $legacyUser->email,
                $legacyPassword,
                now(),
            );
            $this->assertNotNull($claimed);
            $this->assertSame($legacyUserId, $claimed->id);
            $this->assertSame($baselineUsers + 31, User::query()->count());
            $this->assertSame($privilegedEmployee->id, $claimed->employee_profile_id);
            $this->assertSame($employeeHash, $claimed->getRawOriginal('password'));
            $this->assertSame(2, $claimed->security_version);
            $this->assertTrue($claimed->hasRole($role));

            $badClaimEmployee = Employee::query()->where('employee_id', 'REC-P0002')->sole();
            $badClaimUser = User::query()->where('email', 'legacy-privileged-2@production-shape.test')->sole();
            $badClaimBefore = $badClaimUser->getAttributes();
            $this->assertNull(app(DualCredentialIdentityClaim::class)->claim(
                $badClaimEmployee->id,
                $badClaimUser->email,
                'incorrect-password',
                now(),
            ));
            $this->assertSame($badClaimBefore, $badClaimUser->fresh()->getAttributes());
            $this->assertNull($badClaimEmployee->fresh()->user);

            $serviceClaimEmployee = Employee::query()->where('employee_id', 'REC-P0003')->sole();
            $service = User::query()->where('employee_id', 'REC-FROC')->sole();
            $this->assertNull(app(DualCredentialIdentityClaim::class)->claim(
                $serviceClaimEmployee->id,
                $service->email,
                $legacyPassword,
                now(),
            ));
            $this->assertNull($service->fresh()->employee_profile_id);
            $this->assertSame(2, User::query()->whereIn('employee_profile_id', $fixtureEmployeeIds)->count());
            $this->assertSame(235, Employee::query()->whereIn('id', $fixtureEmployeeIds)->whereDoesntHave('user')->count());
        } finally {
            $fixtureEmployeeIds = Employee::query()->where('employee_id', 'like', 'REC-%')->pluck('id');
            DB::table('model_has_roles')->where('role_id', $role->id)->delete();
            User::query()->whereIn('employee_profile_id', $fixtureEmployeeIds)->delete();
            User::query()->where('email', 'like', '%@production-shape.test')->delete();
            Employee::query()->whereIn('id', $fixtureEmployeeIds)->delete();
            $role->delete();
        }
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing')
            && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql'
            && DB::connection()->getDriverName() === 'pgsql') {
            return;
        }

        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            $this->fail('PostgreSQL integration tests require the explicit loopback disposable database configuration.');
        }

        $this->markTestSkipped('This regression requires the explicitly configured disposable PostgreSQL test database.');
    }
}
