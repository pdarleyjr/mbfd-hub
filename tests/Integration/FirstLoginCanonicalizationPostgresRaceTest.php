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
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * Runs JIT creation and privileged-account claim in separate PHP processes.
 * Both production transitions lock the Employee first, so exactly one can
 * consume an unlinked Employee identity and the loser must leave no partial
 * link or second security transition.
 */
#[Group('postgres')]
final class FirstLoginCanonicalizationPostgresRaceTest extends TestCase
{
    public function test_concurrent_create_and_claim_allow_exactly_one_identity_transition(): void
    {
        $this->requireDisposablePostgres();

        $suffix = strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
        $employeePassword = 'employee-race-password';
        $legacyPassword = 'legacy-race-password';
        $employee = Employee::query()->create([
            'employee_id' => 'RACE-'.$suffix,
            'name' => 'Canonical Race Employee',
            'rank' => 'Firefighter',
            'password' => Hash::make($employeePassword),
            'must_change_password' => false,
        ]);
        $legacyUser = User::factory()->create([
            'email' => "legacy-race-{$suffix}@mbfdhub.test",
            'password' => Hash::make($legacyPassword),
            'employee_id' => null,
            'employee_profile_id' => null,
            'account_status' => AccountStatus::PendingActivation,
            'security_version' => 1,
        ]);
        $role = Role::findOrCreate('canonical-race-'.$suffix, 'web');
        $legacyUser->assignRole($role);
        $barrier = tempnam(sys_get_temp_dir(), 'mbfd-canonical-race-');
        if ($barrier === false) {
            $this->fail('Unable to allocate the canonical race barrier.');
        }
        unlink($barrier);

        $create = $this->transitionProcess('create', $employee, $legacyUser, $legacyPassword, $barrier);
        $claim = $this->transitionProcess('claim', $employee, $legacyUser, $legacyPassword, $barrier);

        try {
            $create->start();
            $claim->start();
            usleep(150_000);
            touch($barrier);
            $create->wait();
            $claim->wait();

            $createResult = $this->decodeProcessResult($create);
            $claimResult = $this->decodeProcessResult($claim);
            $winners = array_filter([$createResult, $claimResult], static fn (array $result): bool => $result['status'] === 'won');

            $this->assertCount(1, $winners, json_encode([$createResult, $claimResult], JSON_THROW_ON_ERROR));
            $this->assertSame(1, User::query()->where('employee_profile_id', $employee->id)->count());
            $linked = User::query()->where('employee_profile_id', $employee->id)->firstOrFail();
            $this->assertSame($employee->employee_id, $linked->employee_id);
            $this->assertSame(AccountStatus::Active, $linked->account_status);
            $this->assertContains($linked->security_version, [1, 2]);

            $beforeReplayVersion = $linked->security_version;
            $beforeReplayCount = User::query()->count();
            if ($linked->id === $legacyUser->id) {
                $this->assertNull(app(DualCredentialIdentityClaim::class)->claim(
                    $employee->id,
                    $legacyUser->email,
                    $legacyPassword,
                    now(),
                ));
            } else {
                $replay = app(CanonicalUserProvisioner::class)->create(
                    $employee->id,
                    'LEGACY_HUMAN_BCRYPT_UNCHANGED',
                    now(),
                );
                $this->assertFalse($replay['created']);
                $this->assertSame($linked->id, $replay['user']->id);
            }

            $this->assertSame($beforeReplayCount, User::query()->count());
            $this->assertSame($beforeReplayVersion, $linked->fresh()->security_version);
        } finally {
            if ($create->isRunning()) {
                $create->stop();
            }
            if ($claim->isRunning()) {
                $claim->stop();
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            DB::table('model_has_roles')->where('model_id', $legacyUser->id)->delete();
            User::query()->where('employee_profile_id', $employee->id)->delete();
            User::query()->whereKey($legacyUser->id)->delete();
            Employee::query()->whereKey($employee->id)->delete();
            $role->delete();
        }
    }

    private function transitionProcess(
        string $operation,
        Employee $employee,
        User $legacyUser,
        string $legacyPassword,
        string $barrier,
    ): SymfonyProcess {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$deadline = microtime(true) + 10;
while (! is_file(getenv('RACE_BARRIER'))) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for race barrier.');
    }
    usleep(10_000);
}
try {
    if (getenv('RACE_OPERATION') === 'create') {
        $result = app(App\Services\Identity\CanonicalUserProvisioner::class)->create(
            (int) getenv('RACE_EMPLOYEE_ID'),
            'LEGACY_HUMAN_BCRYPT_UNCHANGED',
            now(),
        );
        $won = $result['created'];
    } else {
        $result = app(App\Services\Identity\DualCredentialIdentityClaim::class)->claim(
            (int) getenv('RACE_EMPLOYEE_ID'),
            getenv('RACE_LEGACY_EMAIL'),
            getenv('RACE_LEGACY_PASSWORD'),
            now(),
        );
        $won = $result !== null;
    }
    echo json_encode(['status' => $won ? 'won' : 'lost'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'lost', 'exception' => get_class($exception)], JSON_THROW_ON_ERROR);
}
PHP;

        return new SymfonyProcess([
            PHP_BINARY,
            '-d',
            'extension=pdo_pgsql',
            '-r',
            $script,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'RACE_BARRIER' => $barrier,
            'RACE_EMPLOYEE_ID' => (string) $employee->id,
            'RACE_LEGACY_EMAIL' => $legacyUser->email,
            'RACE_LEGACY_PASSWORD' => $legacyPassword,
            'RACE_OPERATION' => $operation,
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV);
    }

    /** @return array{status: string, exception?: string} */
    private function decodeProcessResult(SymfonyProcess $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
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
