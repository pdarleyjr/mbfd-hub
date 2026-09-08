<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Data\IdentityReconciliation\OwnerLedgerEntry;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\DualCredentialIdentityClaim;
use App\Services\Identity\EmployeeProfileService;
use App\Services\IdentityReconciliation\CanonicalCredentialMigration;
use App\Services\IdentityReconciliation\IdentityReconciliationPreview;
use App\Services\Security\EmployeeAccountAdministration;
use App\Services\Security\EmployeeWorkgroupAdministration;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EmployeeAdministrationLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Lock-order-current-password!';

    public static function administrativeWriters(): array
    {
        return array_map(fn (string $operation): array => [$operation], ['profile', 'create', 'approve', 'unlinked', 'email', 'workgroups']);
    }

    #[DataProvider('administrativeWriters')]
    public function test_multi_user_writers_take_critical_admin_guard_before_participants(string $operation): void
    {
        $target = User::factory()->create(['account_status' => 'active']);
        $actor = User::factory()->create(['account_status' => 'active', 'password' => self::PASSWORD]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $employee = Employee::create(['employee_id' => 'LOCK-ORDER-001', 'name' => 'Lock Order Employee', 'password' => self::PASSWORD]);
        if ($operation === 'profile') {
            $target->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        }
        $queries = [];
        $this->captureTransactionIdentityQueries($queries);
        $accounts = app(EmployeeAccountAdministration::class);
        match ($operation) {
            'profile' => app(EmployeeProfileService::class)->update($actor, $employee, ['phone' => '555-0100']),
            'create' => $accounts->createForEmployee($actor, $employee, 'Temporary-new-account-password!', self::PASSWORD, 'Approved account'),
            'approve' => $accounts->approveNonemployee($actor, $target, self::PASSWORD, 'Approved nonemployee'),
            'unlinked' => $accounts->updateUnlinkedProfile($actor, $target, ['phone' => '555-0100']),
            'email' => $accounts->changeUnlinkedRecoveryEmail($actor, $target, 'lock-order@example.test', self::PASSWORD, 'Approved recovery change'),
            'workgroups' => app(EmployeeWorkgroupAdministration::class)->sync($actor, $target, [], self::PASSWORD, 'Approved workgroups'),
        };

        self::assertNotEmpty($queries);
        self::assertStringContainsString('"account_status"', $queries[0], 'The shared critical-administrator lock must precede participant locks.');
    }

    public static function canonicalTransitions(): array
    {
        return [['create'], ['claim']];
    }

    #[DataProvider('canonicalTransitions')]
    public function test_existing_canonical_transition_locks_user_before_employee(string $operation): void
    {
        $employee = Employee::create(['employee_id' => 'LOCK-TRANSITION-001', 'name' => 'Transition Lock Employee', 'password' => self::PASSWORD]);
        $provisioner = app(CanonicalUserProvisioner::class);
        if ($operation === 'create') {
            $user = $provisioner->create($employee->id, 'LEGACY_HUMAN_BCRYPT_UNCHANGED', now())['user'];
        } else {
            $user = User::factory()->create(['password' => self::PASSWORD]);
            $user->assignRole(Role::findOrCreate('member', 'web'));
        }
        $queries = [];
        $this->captureTransactionIdentityQueries($queries);
        if ($operation === 'create') {
            self::assertFalse($provisioner->create($employee->id, 'LEGACY_HUMAN_BCRYPT_UNCHANGED', now())['created']);
        } else {
            self::assertSame($user->id, app(DualCredentialIdentityClaim::class)->claim($employee->id, $user->email, self::PASSWORD, now())?->id);
        }
        self::assertStringContainsString('from "users"', $queries[0]);
        self::assertStringContainsString('from "employees"', $queries[1]);
    }

    public function test_multi_record_ledger_prelocks_all_existing_users_before_employees(): void
    {
        $ledger = [];
        $userIds = [];
        foreach (['001', '002'] as $suffix) {
            $employee = Employee::create(['employee_id' => 'LOCK-LEDGER-'.$suffix, 'name' => 'Ledger Employee '.$suffix, 'password' => self::PASSWORD]);
            $user = User::factory()->create(['employee_id' => $employee->employee_id, 'name' => $employee->name, 'account_status' => 'active']);
            $userIds[] = $user->id;
            $ledger[] = new OwnerLedgerEntry($user->id, $employee->employee_id, 'LINK', 'Synthetic owner', '2026-09-08T12:00:00Z', 'LOCK-ORDER-TEST', null, 'PRESERVE_CANONICAL_HASH');
        }
        $ledger = array_reverse($ledger);
        $preview = app(IdentityReconciliationPreview::class)->build($ledger);
        $queries = [];
        $this->captureTransactionIdentityQueries($queries);
        $result = app(CanonicalCredentialMigration::class)->apply($ledger, $preview['snapshot_token'], 'APPLY_OWNER_APPROVED_LINKS');
        self::assertSame(2, $result['links_applied']);
        $participantLock = $employeeLock = null;
        foreach ($queries as $index => $sql) {
            if (str_contains($sql, 'from "users" where "users"."id" in') && str_contains($sql, 'order by "id"')) {
                $participantLock ??= $index;
            }
            if (str_contains($sql, 'from "employees" where "employees"."id" in') && str_contains($sql, 'order by "id"')) {
                $employeeLock ??= $index;
            }
        }
        self::assertNotNull($participantLock);
        self::assertNotNull($employeeLock);
        self::assertLessThan($employeeLock, $participantLock);
        foreach ($userIds as $userId) {
            self::assertNotNull(User::findOrFail($userId)->employee_profile_id);
        }
    }

    private function captureTransactionIdentityQueries(array &$queries): void
    {
        $inOperation = false;
        Event::listen(TransactionBeginning::class, function () use (&$inOperation): void {
            $inOperation = true;
        });
        DB::listen(function (QueryExecuted $query) use (&$queries, &$inOperation): void {
            if ($inOperation && str_starts_with($query->sql, 'select')
                && (str_contains($query->sql, 'from "users"') || str_contains($query->sql, 'from "employees"'))) {
                $queries[] = $query->sql;
            }
        });
    }
}
