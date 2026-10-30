<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

/**
 * Independent PHP workers contend for one duty date's pointer and for one ledger row. The parent holds
 * the row until PostgreSQL reports both workers waiting on a lock, so a sequential replay cannot satisfy
 * these tests. Dates sit far in the future so they cannot meet real rows.
 */
#[Group('postgres')]
final class DailyRosterPostgresRaceTest extends TestCase
{
    private const DATE = '2190-03-09';

    /** @var list<int> */
    private array $importIds = [];

    public function test_two_exports_racing_for_one_date_end_with_the_newer_one_showing(): void
    {
        $this->requireDisposablePostgres();
        $this->clearDate();
        // Generated times differ, and whichever worker wins the lock first, the newer export must win overall.
        $older = $this->validatedImport('2190-03-09 13:00:00');
        $newer = $this->validatedImport('2190-03-09 13:30:00');
        $now = CarbonImmutable::now('UTC');
        RosterActiveVersion::query()->insertOrIgnore(['duty_date' => self::DATE, 'created_at' => $now, 'updated_at' => $now]);

        $this->raceHoldingRow(
            static fn () => RosterActiveVersion::query()->whereDate('duty_date', self::DATE)->lockForUpdate()->firstOrFail(),
            [
                ['import' => $older, 'generated' => '2190-03-09 13:00:00', 'variant' => 'OLD'],
                ['import' => $newer, 'generated' => '2190-03-09 13:30:00', 'variant' => 'NEW'],
            ],
            'publish',
        );

        $pointer = RosterActiveVersion::query()->whereDate('duty_date', self::DATE)->firstOrFail();
        $newer = $newer->fresh();
        $older = $older->fresh();
        self::assertSame((int) $newer->roster_snapshot_id, (int) $pointer->roster_snapshot_id);
        self::assertSame(RosterImport::STATE_PUBLISHED, $newer->state);
        self::assertContains($older->state, [RosterImport::STATE_PUBLISHED, RosterImport::STATE_SUPERSEDED]);
        self::assertSame(2, RosterSnapshot::query()->whereDate('duty_date', self::DATE)->count());
        self::assertSame([1, 2], RosterSnapshot::query()->whereDate('duty_date', self::DATE)->orderBy('version')->pluck('version')->map(static fn ($version): int => (int) $version)->all());
        self::assertTrue($pointer->effective_generated_at->equalTo('2190-03-09 13:30:00'));
    }

    public function test_only_one_worker_can_claim_a_row(): void
    {
        $this->requireDisposablePostgres();
        $this->clearDate();
        Storage::fake('local');
        $import = RosterPipelineFixtures::import();
        $this->importIds[] = (int) $import->id;

        $results = $this->raceHoldingRow(
            static fn () => RosterImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail(),
            [['import' => $import], ['import' => $import]],
            'claim',
        );

        sort($results);
        self::assertSame(['claimed', 'lost'], $results);
        self::assertSame(RosterImport::STATE_PARSING, $import->fresh()->state);
        self::assertSame(1, (int) $import->fresh()->attempts);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->clearDate();
        }
        parent::tearDown();
    }

    /**
     * @param  callable(): mixed  $lock
     * @param  list<array<string, mixed>>  $work
     * @return list<string>
     */
    private function raceHoldingRow(callable $lock, array $work, string $action): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'mbfd-roster-race-');
        if ($barrier === false) {
            $this->fail('Unable to allocate the race barrier.');
        }
        unlink($barrier);

        $suffix = bin2hex(random_bytes(8));
        $names = [];
        $workers = [];
        foreach ($work as $index => $item) {
            $name = 'mbfd-roster-race-'.$suffix.'-'.$index;
            $names[] = $name;
            $workers[] = $this->worker($action, (int) $item['import']->id, (string) ($item['generated'] ?? ''), (string) ($item['variant'] ?? ''), $barrier, $name);
        }

        try {
            DB::beginTransaction();
            $lock();
            foreach ($workers as $worker) {
                $worker->start();
            }
            $this->waitFor($workers, static fn (): bool => collect($names)->every(static fn (string $name): bool => is_file($barrier.'.'.$name)));
            touch($barrier);
            $this->waitFor($workers, static function () use ($names): bool {
                DB::select('SELECT pg_stat_clear_snapshot()');

                return DB::table('pg_stat_activity')->whereIn('application_name', $names)->where('wait_event_type', 'Lock')->count() === count($names);
            });
            DB::commit();

            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = trim($worker->getOutput());
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$barrier, ...array_map(static fn (string $name): string => $barrier.'.'.$name, $names)] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** @param list<SymfonyProcess> $workers */
    private function waitFor(array $workers, callable $condition): void
    {
        $deadline = microtime(true) + 15;
        while (! $condition()) {
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning(), 'Race worker exited before the shared lock barrier: '.$worker->getErrorOutput().$worker->getOutput());
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for the workers at the PostgreSQL lock barrier.');
            }
            usleep(10_000);
        }
    }

    private function worker(string $action, int $importId, string $generated, string $variant, string $barrier, string $name): SymfonyProcess
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select('SELECT set_config(?, ?, false)', ['application_name', getenv('RACE_NAME')]);
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '20s'");
touch(getenv('RACE_BARRIER').'.'.getenv('RACE_NAME'));
$deadline = microtime(true) + 15;
while (! is_file(getenv('RACE_BARRIER'))) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the race barrier.');
    }
    usleep(10_000);
}
$import = App\Models\DailyRoster\RosterImport::query()->findOrFail((int) getenv('RACE_IMPORT'));
if (getenv('RACE_ACTION') === 'claim') {
    echo app(App\Services\DailyRoster\RosterStateRecorder::class)->claim($import) === null ? 'lost' : 'claimed';
} else {
    $report = Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures::report(getenv('RACE_DATE'), [
        'generated_at' => getenv('RACE_GENERATED'), 'variant' => getenv('RACE_VARIANT'),
    ]);
    app(App\Services\DailyRoster\RosterPublisher::class)->publish($import, $report);
    echo 'done';
}
PHP;

        return new SymfonyProcess([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-d', 'display_errors=stderr', '-r', $script], base_path(), [
            'APP_ENV' => 'testing', 'RACE_BARRIER' => $barrier, 'RACE_IMPORT' => (string) $importId, 'RACE_ACTION' => $action,
            'RACE_DATE' => self::DATE, 'RACE_GENERATED' => $generated, 'RACE_VARIANT' => $variant, 'RACE_NAME' => $name,
            'CAPTAIN5_SHIFT_ROTATION_ENABLED' => 'true',
            'SystemRoot' => (string) getenv('SystemRoot'), 'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV, timeout: 40);
    }

    private function validatedImport(string $generatedAt): RosterImport
    {
        $import = RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => RosterImport::STATE_VALIDATED,
            'state_changed_at' => CarbonImmutable::now('UTC'),
            'message_id_sha256' => hash('sha256', 'race-'.Str::uuid()),
            'sender' => 'donotreply@example.test',
            'received_at' => CarbonImmutable::now('UTC'),
            'duty_date' => self::DATE,
            'source_generated_at' => $generatedAt,
        ]);
        $this->importIds[] = (int) $import->id;

        return $import;
    }

    /** Immutable rows refuse model deletes, and this database is disposable, so the query builder clears them. */
    private function clearDate(): void
    {
        $snapshotIds = DB::table('roster_snapshots')->whereDate('duty_date', self::DATE)->pluck('id');
        DB::table('roster_active_versions')->whereDate('duty_date', self::DATE)->delete();
        DB::table((new RosterAssignment)->getTable())->whereIn('roster_snapshot_id', $snapshotIds)->delete();
        DB::table((new RosterSection)->getTable())->whereIn('roster_snapshot_id', $snapshotIds)->delete();
        DB::table('roster_imports')->whereIn('id', $this->importIds)->update(['roster_snapshot_id' => null]);
        DB::table((new RosterSnapshot)->getTable())->whereIn('id', $snapshotIds)->delete();
        DB::table((new RosterEvent)->getTable())->whereIn('roster_import_id', $this->importIds)->delete();
        DB::table('roster_imports')->whereIn('id', $this->importIds)->delete();
        $this->importIds = [];
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing') && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql' && DB::connection()->getDriverName() === 'pgsql') {
            return;
        }
        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            $this->fail('This race test requires the explicit loopback disposable PostgreSQL test configuration.');
        }
        $this->markTestSkipped('This regression requires an explicitly configured disposable PostgreSQL test database.');
    }
}
