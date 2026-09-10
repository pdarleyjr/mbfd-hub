<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class TemporaryCredentialFingerprintPostgresRaceTest extends TestCase
{
    public function test_concurrent_duplicate_temporary_fingerprints_allow_exactly_one_winner(): void
    {
        $this->requireDisposablePostgres();

        $users = User::factory()->count(2)->create();
        $fingerprint = hash('sha256', random_bytes(32));
        $barrier = tempnam(sys_get_temp_dir(), 'mbfd-temp-fingerprint-race-');
        if ($barrier === false) {
            $this->fail('Unable to allocate the temporary-credential race barrier.');
        }
        unlink($barrier);
        $readyFiles = [$barrier.'.first-ready', $barrier.'.second-ready'];
        $processes = $users->values()
            ->map(fn (User $user, int $index): Process => $this->updateProcess($user, $fingerprint, $barrier, $readyFiles[$index]))
            ->all();

        try {
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 15;
            while (! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Both temporary-credential updates did not reach the race barrier.');
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

            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'won')), json_encode($results, JSON_THROW_ON_ERROR));
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'duplicate')), json_encode($results, JSON_THROW_ON_ERROR));
            self::assertSame(1, User::query()->where('temporary_credential_fingerprint', $fingerprint)->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, ...$readyFiles] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            User::query()->whereKey($users->modelKeys())->delete();
        }
    }

    private function updateProcess(User $user, string $fingerprint, string $barrier, string $ready): Process
    {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch(getenv('TEMP_FINGERPRINT_RACE_READY'));
$deadline = microtime(true) + 15;
while (! is_file(getenv('TEMP_FINGERPRINT_RACE_BARRIER'))) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Temporary-credential race barrier timed out.');
    }
    usleep(10_000);
}
try {
    Illuminate\Support\Facades\DB::transaction(function (): void {
        App\Models\User::query()->whereKey((int) getenv('TEMP_FINGERPRINT_USER_ID'))->update([
            'temporary_credential_fingerprint' => getenv('TEMP_FINGERPRINT_VALUE'),
        ]);
    });
    echo json_encode(['status' => 'won'], JSON_THROW_ON_ERROR);
} catch (Illuminate\Database\UniqueConstraintViolationException) {
    echo json_encode(['status' => 'duplicate'], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', $script], base_path(), [
            'APP_ENV' => 'testing',
            'TEMP_FINGERPRINT_RACE_BARRIER' => $barrier,
            'TEMP_FINGERPRINT_RACE_READY' => $ready,
            'TEMP_FINGERPRINT_USER_ID' => (string) $user->id,
            'TEMP_FINGERPRINT_VALUE' => $fingerprint,
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
