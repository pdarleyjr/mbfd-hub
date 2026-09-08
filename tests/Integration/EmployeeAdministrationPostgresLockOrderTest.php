<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Security\AccountSecurityService;
use App\Services\Security\LastCriticalAdministratorGuard;
use App\Services\Security\RoleAssignmentService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class EmployeeAdministrationPostgresLockOrderTest extends TestCase
{
    private const PASSWORD = 'Disposable-lock-order-password!';

    public static function writers(): array
    {
        return array_map(fn (string $writer): array => [$writer], ['profile', 'unlinked', 'approve', 'email', 'workgroups', 'promote', 'canonical_create', 'claim']);
    }

    #[DataProvider('writers')]
    public function test_concurrent_writers_do_not_hold_a_lower_order_lock_while_waiting(string $writer): void
    {
        $this->requireDisposablePostgres();
        $suffix = bin2hex(random_bytes(6));
        $employee = Employee::create(['employee_id' => 'LOCK-'.$suffix, 'name' => 'Disposable Lock Employee', 'password' => self::PASSWORD]);
        $target = $writer === 'canonical_create'
            ? app(CanonicalUserProvisioner::class)->create($employee->id, 'LEGACY_HUMAN_BCRYPT_UNCHANGED', now())['user']
            : User::factory()->create(['account_status' => $writer === 'claim' ? 'pending_activation' : 'active', 'password' => self::PASSWORD]);
        $target->assignRole(Role::findOrCreate('member', 'web'));
        if (in_array($writer, ['profile', 'promote'], true)) {
            $target->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        }
        // The lower-ID member is essential: old sorted-participant writers
        // locked this row before waiting on the lifecycle's higher-ID admin.
        $actor = User::factory()->create(['account_status' => 'active', 'password' => self::PASSWORD]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        self::assertLessThan($actor->id, $target->id);
        $application = 'mbfd-lock-'.$suffix;
        $child = $this->writerProcess($writer, $actor, $target, $employee, $application);
        $canonical = in_array($writer, ['canonical_create', 'claim'], true);

        try {
            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '3s'");
            if ($canonical) {
                User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            } else {
                app(LastCriticalAdministratorGuard::class)->lockActiveCriticalAdministrators();
            }
            $child->start();
            $waitEvent = $this->waitUntilChildIsBlocked($child, $application);
            if (! $canonical) {
                self::assertSame('advisory', $waitEvent, 'Administrative writers must wait on the stable mutex before any changing critical-row snapshot.');
            }

            // In the former code the child already held the next row needed
            // here, creating a real two-connection deadlock. Now it waits at the
            // common first lock, so the parent completes while the child waits.
            if ($canonical) {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            } elseif ($writer === 'promote') {
                app(RoleAssignmentService::class)->syncWithAuthorization($actor, $target, ['super_admin'], self::PASSWORD, 'Disposable critical-membership change');
            } else {
                app(AccountSecurityService::class)->revokeSessions($actor, $target, 'Disposable concurrent security change', now(), self::PASSWORD);
            }
            DB::commit();
            $child->wait();
            self::assertTrue($child->isSuccessful(), $child->getErrorOutput());
            $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('completed', $result['status'] ?? null, json_encode($result, JSON_THROW_ON_ERROR));
            self::assertSame($target->id, $target->fresh()->id);
            if ($writer === 'promote') {
                self::assertTrue($target->fresh()->hasRole('super_admin'));
                self::assertSame('555-0199', $employee->fresh()->phone);
            } elseif (! $canonical) {
                self::assertSame($writer === 'email' ? 3 : 2, $target->fresh()->security_version);
                $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'revoke_sessions', 'result' => 'allowed']);
            }
            if ($writer === 'profile') {
                self::assertSame('555-0199', $employee->fresh()->phone);
            } elseif ($canonical) {
                self::assertSame($employee->id, $target->fresh()->employee_profile_id);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($child->isRunning()) {
                $child->stop();
            }
            // Only fresh synthetic fixtures in the explicitly disposable DB.
            DB::table('security_action_events')->whereIn('target_user_id', [$actor->id, $target->id])->delete();
            DB::table('employee_profile_events')->where('employee_id', $employee->id)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', [$actor->id, $target->id])->delete();
            User::query()->whereKey([$actor->id, $target->id])->delete();
            Employee::query()->whereKey($employee->id)->delete();
        }
    }

    private function waitUntilChildIsBlocked(Process $child, string $application): string
    {
        $deadline = microtime(true) + 15;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $state = DB::selectOne('select wait_event_type, wait_event from pg_stat_activity where application_name = ?', [$application]);
            if ($state?->wait_event_type === 'Lock') {
                return (string) $state->wait_event;
            }
            if (! $child->isRunning()) {
                self::fail('Child exited before the lock barrier: '.$child->getOutput().$child->getErrorOutput());
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('The child did not reach a PostgreSQL lock wait.');
    }

    private function writerProcess(string $writer, User $actor, User $target, Employee $employee, string $application): Process
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || getenv('EXPECTED_TEST_DB_CONNECTION') !== 'pgsql'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('Only explicit disposable PostgreSQL is permitted.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Process::preventStrayProcesses();
Illuminate\Support\Facades\DB::select('select set_config(?, ?, false)', ['application_name', getenv('LOCK_APPLICATION')]);
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '10s'");
$actor = App\Models\User::findOrFail((int) getenv('LOCK_ACTOR'));
$target = App\Models\User::findOrFail((int) getenv('LOCK_TARGET'));
$employee = App\Models\Employee::findOrFail((int) getenv('LOCK_EMPLOYEE'));
$accounts = app(App\Services\Security\EmployeeAccountAdministration::class);
try {
    match (getenv('LOCK_WRITER')) {
        'profile', 'promote' => app(App\Services\Identity\EmployeeProfileService::class)->update($actor, $employee, ['phone' => '555-0199']),
        'unlinked' => $accounts->updateUnlinkedProfile($actor, $target, ['phone' => '555-0199']),
        'approve' => $accounts->approveNonemployee($actor, $target, getenv('LOCK_PASSWORD'), 'Disposable approval'),
        'email' => $accounts->changeUnlinkedRecoveryEmail($actor, $target, getenv('LOCK_APPLICATION').'@example.test', getenv('LOCK_PASSWORD'), 'Disposable recovery change'),
        'workgroups' => app(App\Services\Security\EmployeeWorkgroupAdministration::class)->sync($actor, $target, [], getenv('LOCK_PASSWORD'), 'Disposable workgroups'),
        'canonical_create' => app(App\Services\Identity\CanonicalUserProvisioner::class)->create($employee->id, 'LEGACY_HUMAN_BCRYPT_UNCHANGED', now()),
        'claim' => app(App\Services\Identity\DualCredentialIdentityClaim::class)->claim($employee->id, $target->email, getenv('LOCK_PASSWORD'), now()),
    };
    echo json_encode(['status' => 'completed'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'failed', 'exception_class' => $exception::class, 'code' => $exception->getCode()], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', $script], base_path(), [
            'APP_ENV' => 'testing', 'LOCK_WRITER' => $writer, 'LOCK_ACTOR' => (string) $actor->id,
            'LOCK_TARGET' => (string) $target->id, 'LOCK_EMPLOYEE' => (string) $employee->id,
            'LOCK_APPLICATION' => $application, 'LOCK_PASSWORD' => self::PASSWORD,
            'SystemRoot' => (string) getenv('SystemRoot'), 'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV, timeout: 30);
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing') && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql' && DB::connection()->getDriverName() === 'pgsql') {
            return;
        }
        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            self::fail('The lock-order gate requires explicitly configured loopback disposable PostgreSQL.');
        }
        $this->markTestSkipped('Requires the disposable PostgreSQL two-connection gate.');
    }
}
