<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class EmployeePasswordResetPostgresRaceTest extends TestCase
{
    public function test_simultaneous_http_resets_consume_one_token_and_revoke_the_old_session_once(): void
    {
        $this->requireDisposablePostgres();
        $suffix = bin2hex(random_bytes(6));
        $employee = Employee::query()->create([
            'employee_id' => 'RESET-RACE-'.$suffix, 'name' => 'Reset Race Member',
            'password' => Hash::make('old-race-password'), 'must_change_password' => true,
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'email' => 'reset-race-'.$suffix.'@miamibeachfl.gov', 'account_status' => AccountStatus::Active,
            'password' => Hash::make('old-race-password'), 'must_change_password' => true, 'security_version' => 7,
        ]);
        $session = AuthenticationSession::factory()->create(['user_id' => $user->id, 'security_version' => 7]);
        $token = Password::broker()->createToken($user);
        $barrier = tempnam(sys_get_temp_dir(), 'mbfd-reset-race-');
        if ($barrier === false) {
            $this->fail('Unable to allocate the password-reset race barrier.');
        }
        unlink($barrier);
        $passwords = ['first-race-replacement-2026', 'second-race-replacement-2026'];
        $processes = [
            $this->resetProcess($user, $token, $passwords[0], $barrier, $barrier.'.first-ready'),
            $this->resetProcess($user, $token, $passwords[1], $barrier, $barrier.'.second-ready'),
        ];

        try {
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 15;
            // Each child signals only after its real reset transaction begins.
            // Both transactions overlap before either can attempt the user lock.
            while (! is_file($barrier.'.first-ready') || ! is_file($barrier.'.second-ready')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Both HTTP reset transactions did not reach the race barrier.');
                }
                usleep(10_000);
            }
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            self::assertSame(1, count(array_filter($statuses, fn (string $status): bool => $status === 'won')), json_encode($results, JSON_THROW_ON_ERROR));
            self::assertSame(1, count(array_filter($statuses, fn (string $status): bool => $status === 'rejected')), json_encode($results, JSON_THROW_ON_ERROR));
            $winner = array_search('won', $statuses, true);
            self::assertIsInt($winner);
            $current = $user->fresh();
            self::assertTrue(Hash::check($passwords[$winner], $current->password));
            self::assertFalse(Hash::check($passwords[1 - $winner], $current->password));
            self::assertFalse(Hash::check('old-race-password', $current->password));
            self::assertSame(8, $current->security_version);
            self::assertFalse($current->must_change_password);
            self::assertNotNull($session->fresh()->revoked_at);
            self::assertSame('password changed', $session->fresh()->revoked_reason);
            self::assertSame(1, AuthenticationSession::query()->where('user_id', $user->id)->count());
            self::assertSame($employee->id, $current->employee_profile_id);
            self::assertSame($employee->employee_id, $current->employee_id);
            self::assertSame($user->email, $current->email);
            self::assertTrue(Hash::check('old-race-password', $employee->fresh()->password));
            self::assertFalse(Password::broker()->tokenExists($current, $token));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, $barrier.'.first-ready', $barrier.'.second-ready'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            Password::broker()->deleteToken($user);
            AuthenticationSession::query()->where('user_id', $user->id)->delete();
            User::query()->whereKey($user->id)->delete();
            Employee::query()->whereKey($employee->id)->delete();
        }
    }

    private function resetProcess(User $user, string $token, string $password, string $barrier, string $ready): Process
    {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || getenv('EXPECTED_TEST_DB_CONNECTION') !== 'pgsql'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('The child reset request requires disposable PostgreSQL.');
}
config(['session.driver' => 'array', 'cache.default' => 'array']);
Illuminate\Support\Facades\Http::preventStrayRequests();
$armed = true;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionBeginning::class, function () use (&$armed): void {
    if (! $armed) {
        return;
    }
    $armed = false;
    touch(getenv('RESET_RACE_READY'));
    $deadline = microtime(true) + 20;
    while (! is_file(getenv('RESET_RACE_BARRIER'))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Reset transaction barrier timed out.');
        }
        usleep(10_000);
    }
});
$request = Illuminate\Http\Request::create('/reset-password', 'POST', [
    'employee_id' => getenv('RESET_RACE_EMPLOYEE_ID'),
    'token' => getenv('RESET_RACE_TOKEN'),
    'password' => getenv('RESET_RACE_PASSWORD'),
    'password_confirmation' => getenv('RESET_RACE_PASSWORD'),
]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$errors = $request->session()->get('errors');
$status = $response->getStatusCode() === 302 && $request->session()->get('status') === 'Your password has been reset.'
    ? 'won' : ($response->getStatusCode() === 302 && $errors?->has('employee_id') ? 'rejected' : 'unexpected');
echo json_encode(['status' => $status, 'http_status' => $response->getStatusCode()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;

        return new Process([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', $script], base_path(), [
            'APP_ENV' => 'testing',
            'RESET_RACE_EMPLOYEE_ID' => $user->employee_id,
            'RESET_RACE_TOKEN' => $token,
            'RESET_RACE_PASSWORD' => $password,
            'RESET_RACE_BARRIER' => $barrier,
            'RESET_RACE_READY' => $ready,
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV, timeout: 30);
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
