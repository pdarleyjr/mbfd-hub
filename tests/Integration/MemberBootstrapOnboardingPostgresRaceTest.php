<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

#[Group('postgres')]
final class MemberBootstrapOnboardingPostgresRaceTest extends TestCase
{
    public function test_concurrent_disable_reset_and_activation_cannot_be_overwritten_by_stale_completion(): void
    {
        $this->requireDisposablePostgres();

        foreach (['disable', 'reset', 'activate'] as $operation) {
            $suffix = strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
            $employee = Employee::query()->create([
                'employee_id' => 'BOOT-RACE-'.$suffix,
                'name' => 'Bootstrap Race Member',
                'roster_status' => 'active',
                'city_email' => 'original-'.$suffix.'@miamibeachfl.gov',
                'password' => Hash::make('unusable-roster-password'),
            ]);
            $originalHash = Hash::make('unrecoverable-placeholder');
            $user = User::factory()->create([
                'employee_profile_id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'email' => 'original-'.$suffix.'@miamibeachfl.gov',
                'password' => $originalHash,
                'account_status' => AccountStatus::PendingActivation,
                'security_version' => 1,
                'must_change_password' => true,
                'bootstrap_onboarding_eligible' => true,
                'bootstrap_onboarding_eligible_at' => now(),
            ]);
            $ready = tempnam(sys_get_temp_dir(), 'mbfd-bootstrap-race-');
            if ($ready === false) {
                $this->fail('Unable to allocate the bootstrap race barrier.');
            }
            unlink($ready);
            $completion = $this->completionProcess($user, $employee, $ready, $suffix);

            try {
                DB::transaction(function () use ($operation, $user, $ready, $completion): void {
                    User::query()->lockForUpdate()->findOrFail($user->id);
                    $completion->start();
                    $deadline = microtime(true) + 10;
                    while (! is_file($ready)) {
                        if (microtime(true) >= $deadline) {
                            throw new \RuntimeException('Timed out waiting for completion process.');
                        }
                        usleep(10_000);
                    }
                    usleep(100_000);

                    $security = app(AccountSecurityService::class);
                    if ($operation === 'disable') {
                        $security->disable($user, 'concurrent disable', now());
                    } elseif ($operation === 'reset') {
                        $security->setAdministrativeRecoveryPassword(
                            $user,
                            Hash::make('administrator-reset-password'),
                            hash('sha256', 'test-reset-fingerprint'),
                            now(),
                        );
                    } else {
                        $security->activateWithTemporaryPassword(
                            $user,
                            Hash::make('alternate-activation-password'),
                            hash('sha256', 'test-activation-fingerprint'),
                            now(),
                        );
                    }
                });
                $completion->wait();
                $result = json_decode($completion->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('rejected_stale', $result['status'], $completion->getErrorOutput());

                $user->refresh();
                $employee->refresh();
                self::assertSame('original-'.$suffix.'@miamibeachfl.gov', $user->email);
                self::assertSame('original-'.$suffix.'@miamibeachfl.gov', $employee->city_email);
                self::assertNull($user->bootstrap_onboarding_completed_at);
                if ($operation === 'disable') {
                    self::assertSame(AccountStatus::Disabled, $user->account_status);
                    self::assertSame($originalHash, $user->getRawOriginal('password'));
                    self::assertNull($user->temporary_credential_fingerprint);
                } else {
                    self::assertNotSame($originalHash, $user->getRawOriginal('password'));
                    self::assertNotNull($user->temporary_credential_fingerprint, $operation);
                }
                self::assertFalse($user->bootstrap_onboarding_eligible);
            } finally {
                if ($completion->isRunning()) {
                    $completion->stop();
                }
                if (is_file($ready)) {
                    unlink($ready);
                }
                DB::table('security_action_events')->where('target_user_id', $user->id)->delete();
                DB::table('users')->where('id', $user->id)->delete();
                DB::table('employees')->where('id', $employee->id)->delete();
            }
        }
    }

    private function completionProcess(User $user, Employee $employee, string $ready, string $suffix): SymfonyProcess
    {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch(getenv('RACE_READY'));
try {
    app(App\Services\Identity\AccountSecurityService::class)->completeMemberBootstrap(
        (int) getenv('RACE_USER_ID'),
        (int) getenv('RACE_EMPLOYEE_ID'),
        1,
        getenv('RACE_CITY_EMAIL'),
        Illuminate\Support\Facades\Hash::make('completed-private-password'),
        now(),
    );
    echo json_encode(['status' => 'completed'], JSON_THROW_ON_ERROR);
} catch (App\Exceptions\MemberBootstrapStateChanged) {
    echo json_encode(['status' => 'rejected_stale'], JSON_THROW_ON_ERROR);
}
PHP;

        return new SymfonyProcess([
            PHP_BINARY,
            '-r',
            $script,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'MBFD_MEMBER_BOOTSTRAP_ENABLED' => 'true',
            'MBFD_MEMBER_BOOTSTRAP_PASSWORD_HASH' => Hash::make('test-only-bootstrap-race'),
            'RACE_READY' => $ready,
            'RACE_USER_ID' => (string) $user->id,
            'RACE_EMPLOYEE_ID' => (string) $employee->id,
            'RACE_CITY_EMAIL' => 'completed-'.$suffix.'@miamibeachfl.gov',
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV);
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
