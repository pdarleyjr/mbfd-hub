<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\TrtInventorySession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

#[Group('postgres')]
final class TrtInventoryPostgresIntegrityTest extends TestCase
{
    public function test_postgresql_partial_index_blocks_only_duplicate_default_sessions(): void
    {
        $this->requireDisposablePostgres();

        $sessionDate = now()->addYears(10)->toDateString();
        $timestamp = now();

        try {
            $index = DB::selectOne(<<<'SQL'
                SELECT indexdef
                FROM pg_indexes
                WHERE schemaname = current_schema()
                  AND tablename = 'trt_inventory_sessions'
                  AND indexname = 'trt_inventory_sessions_default_day_unique'
                SQL);
            $this->assertNotNull($index);
            $this->assertStringContainsString('WHERE (trailer_id IS NULL)', $index->indexdef);

            DB::table('trt_inventory_sessions')->insert([
                'trailer_id' => null,
                'session_date' => $sessionDate,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            DB::table('trt_inventory_sessions')->insert([
                'trailer_id' => random_int(1, 2_000_000_000),
                'session_date' => $sessionDate,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $this->expectException(QueryException::class);

            DB::table('trt_inventory_sessions')->insert([
                'trailer_id' => null,
                'session_date' => $sessionDate,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } finally {
            DB::table('trt_inventory_sessions')->where('session_date', $sessionDate)->delete();
        }
    }

    public function test_migration_detects_historical_null_duplicates_before_creating_the_partial_index(): void
    {
        $this->requireDisposablePostgres();

        $sessionDate = now()->addYears(11)->toDateString();
        $timestamp = now();
        /** @var \Illuminate\Database\Migrations\Migration $migration */
        $migration = require database_path('migrations/2026_08_26_120000_enforce_one_default_trt_session_per_day.php');

        try {
            DB::statement('DROP INDEX IF EXISTS trt_inventory_sessions_default_day_unique');
            DB::table('trt_inventory_sessions')->insert([
                [
                    'trailer_id' => null,
                    'session_date' => $sessionDate,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'trailer_id' => null,
                    'session_date' => $sessionDate,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ]);

            try {
                $migration->up();
                $this->fail('The migration should reject duplicate historical default sessions.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('duplicate historical sessions', $exception->getMessage());
            }

            $index = DB::selectOne(<<<'SQL'
                SELECT 1
                FROM pg_indexes
                WHERE schemaname = current_schema()
                  AND tablename = 'trt_inventory_sessions'
                  AND indexname = 'trt_inventory_sessions_default_day_unique'
                SQL);
            $this->assertNull($index);
        } finally {
            DB::table('trt_inventory_sessions')->where('session_date', $sessionDate)->delete();
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS trt_inventory_sessions_default_day_unique '
                .'ON trt_inventory_sessions (session_date) WHERE trailer_id IS NULL'
            );
        }
    }

    public function test_find_or_create_waits_for_a_concurrent_default_insert_and_returns_one_session(): void
    {
        $this->requireDisposablePostgres();

        Carbon::setTestNow('2042-01-15 10:00:00');
        $sessionDate = now()->toDateString();
        $lockKey = random_int(1_000_000, 2_000_000_000);
        $insertProcess = null;

        try {
            DB::table('trt_inventory_sessions')
                ->whereNull('trailer_id')
                ->where('session_date', $sessionDate)
                ->delete();

            $insertProcess = $this->insertDefaultSessionInSeparateProcess($sessionDate, $lockKey);
            $this->waitUntilAdvisoryLockIsHeld($lockKey, $insertProcess);

            $startedAt = hrtime(true);
            $session = TrtInventorySession::findOrCreateForToday();
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

            $insertProcess->wait();
            $this->assertTrue($insertProcess->isSuccessful(), $insertProcess->getErrorOutput());
            $this->assertGreaterThanOrEqual(0.25, $elapsedSeconds);
            $this->assertSame($sessionDate, $session->session_date->toDateString());
            $this->assertSame(1, DB::table('trt_inventory_sessions')
                ->whereNull('trailer_id')
                ->where('session_date', $sessionDate)
                ->count());
        } finally {
            if ($insertProcess instanceof SymfonyProcess && $insertProcess->isRunning()) {
                $insertProcess->wait();
            }

            DB::table('trt_inventory_sessions')
                ->whereNull('trailer_id')
                ->where('session_date', $sessionDate)
                ->delete();
            Carbon::setTestNow();
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

    private function insertDefaultSessionInSeparateProcess(string $sessionDate, int $lockKey): SymfonyProcess
    {
        $connection = config('database.connections.'.config('database.default'));
        $command = [
            PHP_BINARY,
            '-d',
            'extension=pdo_pgsql',
            '-r',
            <<<'PHP'
$pdo = new PDO(
    getenv('AUDIT_PG_DSN'),
    getenv('AUDIT_PG_USERNAME'),
    getenv('AUDIT_PG_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->beginTransaction();
$insert = $pdo->prepare(
    'INSERT INTO trt_inventory_sessions (trailer_id, session_date, created_at, updated_at) '
    .'VALUES (NULL, :session_date, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
);
$insert->execute(['session_date' => getenv('AUDIT_PG_SESSION_DATE')]);
$pdo->query('SELECT pg_advisory_xact_lock('.(int) getenv('AUDIT_PG_LOCK_KEY').')');
$pdo->query('SELECT pg_sleep(0.6)');
$pdo->commit();
PHP,
        ];

        $process = new SymfonyProcess($command, base_path(), [
            'AUDIT_PG_DSN' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $connection['host'],
                $connection['port'],
                $connection['database'],
            ),
            'AUDIT_PG_LOCK_KEY' => (string) $lockKey,
            'AUDIT_PG_PASSWORD' => (string) $connection['password'],
            'AUDIT_PG_SESSION_DATE' => $sessionDate,
            'AUDIT_PG_USERNAME' => (string) $connection['username'],
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ]);
        $process->start();

        return $process;
    }

    private function waitUntilAdvisoryLockIsHeld(int $lockKey, SymfonyProcess $lockHolder): void
    {
        $deadline = microtime(true) + 5;

        do {
            $result = DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$lockKey]);
            $acquired = filter_var($result?->acquired, FILTER_VALIDATE_BOOL);

            if ($acquired) {
                DB::selectOne('SELECT pg_advisory_unlock(?)', [$lockKey]);
            } else {
                return;
            }

            if (! $lockHolder->isRunning()) {
                $this->fail('The disposable PostgreSQL insert process exited before it acquired the fixture lock: '.$lockHolder->getErrorOutput());
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for the disposable PostgreSQL insert process.');
    }
}
