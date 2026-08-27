<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * Uses a separate local PHP process to hold the same inspection row that the
 * production approval service locks. The service must retry PostgreSQL lock
 * timeouts, then retain one append-only terminal decision.
 *
 * It is deliberately guarded to an explicitly configured disposable test DB.
 */
#[Group('postgres')]
final class ApparatusInspectionApprovalPostgresLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('google_sheets.apparatus_sync_enabled', false);
        Http::preventStrayRequests();
    }

    public function test_postgresql_retries_a_same_record_lock_and_records_one_terminal_decision(): void
    {
        $this->requireDisposablePostgres();

        [$station, $apparatus, $approvalInspection, $rejectionInspection, $reviewer] = $this->pendingInspections();
        Queue::fake();
        $connection = DB::connection();
        $lockHolder = null;

        try {
            $service = app(ApparatusInspectionApprovalService::class);

            $lockKey = random_int(1_000_000, 2_000_000_000);
            $lockHolder = $this->holdInspectionLockInSeparateProcess($approvalInspection->id, $lockKey);
            $this->waitUntilAdvisoryLockIsHeld($lockKey, $lockHolder);

            $connection->statement("SET lock_timeout = '75ms'");
            $startedAt = hrtime(true);
            $approved = $service->approve($approvalInspection->id, $reviewer);
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            $connection->statement('SET lock_timeout = DEFAULT');

            $lockHolder->wait();
            $this->assertTrue($lockHolder->isSuccessful(), $lockHolder->getErrorOutput());
            $this->assertGreaterThanOrEqual(0.25, $elapsedSeconds);
            $this->assertSame('approved', $approved->review_status);

            $repeatApproval = $service->approve($approvalInspection->id, $reviewer);
            $conflictingRejection = $service->reject(
                $approvalInspection->id,
                $reviewer,
                'A later conflicting decision must not replace the terminal approval.',
            );

            $this->assertSame('approved', $approvalInspection->fresh()->review_status);
            $this->assertSame('approved', $repeatApproval->review_status);
            $this->assertSame('approved', $conflictingRejection->review_status);
            $this->assertSame('Out of Service', $apparatus->fresh()->status);
            $this->assertSame(1, DB::table('apparatus_inspection_review_events')
                ->where('apparatus_inspection_id', $approvalInspection->id)
                ->count());
            $this->assertSame(1, DB::table('apparatus_defects')
                ->where('apparatus_inspection_id', $approvalInspection->id)
                ->count());
            Queue::assertPushed(PmAlertNotificationJob::class, 1);
            Queue::assertPushed(AuditEquipmentAfterInspection::class, 1);
        } finally {
            $connection->statement('SET lock_timeout = DEFAULT');

            if ($lockHolder instanceof SymfonyProcess && $lockHolder->isRunning()) {
                $lockHolder->wait();
            }

            $this->deleteFixture($station, $apparatus, $approvalInspection, $rejectionInspection, $reviewer);
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

    private function holdInspectionLockInSeparateProcess(int $inspectionId, int $lockKey): SymfonyProcess
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
$inspection = $pdo->prepare('SELECT id FROM apparatus_inspections WHERE id = :id FOR UPDATE');
$inspection->execute(['id' => (int) getenv('AUDIT_PG_INSPECTION_ID')]);
if ($inspection->fetchColumn() === false) {
    throw new RuntimeException('The disposable PostgreSQL lock fixture was not found.');
}
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
            'AUDIT_PG_INSPECTION_ID' => (string) $inspectionId,
            'AUDIT_PG_LOCK_KEY' => (string) $lockKey,
            'AUDIT_PG_PASSWORD' => (string) $connection['password'],
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
                $this->fail('The disposable PostgreSQL lock holder exited before it acquired the fixture lock: '.$lockHolder->getErrorOutput());
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for the disposable PostgreSQL lock holder.');
    }

    /** @return array{Station, Apparatus, ApparatusInspection, ApparatusInspection, User} */
    private function pendingInspections(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));
        $station = Station::query()->create([
            'station_number' => random_int(900_000, 999_999),
            'name' => "PostgreSQL Review Race {$suffix}",
            'address' => '901 Test Way',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => "E-RACE-{$suffix}",
            'name' => 'Engine Review Race',
            'type' => 'Engine',
            'vehicle_number' => "RACE-{$suffix}",
            'designation' => "E{$suffix}",
            'slug' => "engine-review-race-{$suffix}",
            'make' => 'Test',
            'model' => 'Lock',
            'year' => 2026,
            'status' => 'In Service',
            'current_engine_hours' => 249.0,
            'current_miles' => 10_000,
            'last_pm_engine_hours' => 0.0,
            'last_pm_mileage' => 0,
            'pm_interval_hours' => 300,
            'snipeit_asset_id' => 123,
        ]);
        $approvalInspection = $this->pendingInspection($apparatus, "{$suffix}-approve");
        $rejectionInspection = $this->pendingInspection($apparatus, "{$suffix}-reject");
        $reviewer = User::factory()->create();

        return [$station, $apparatus, $approvalInspection, $rejectionInspection, $reviewer];
    }

    private function pendingInspection(Apparatus $apparatus, string $reference): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'PostgreSQL Review Race Tester',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $apparatus->designation,
            'inspection_reference' => "INS-{$reference}",
            'engine_hours' => 300.0,
            'miles' => 10_020,
            'review_status' => 'pending_review',
            'pending_effects' => [
                'defects' => [[
                    'compartment' => 'Cab',
                    'item' => 'Portable radio',
                    'status' => 'Missing',
                    'notes' => 'Not in assigned mount.',
                ]],
                'has_critical_defects' => true,
            ],
            'completed_at' => now(),
        ]);
    }

    private function deleteFixture(
        Station $station,
        Apparatus $apparatus,
        ApparatusInspection $approvalInspection,
        ApparatusInspection $rejectionInspection,
        User $reviewer,
    ): void {
        $inspectionIds = [$approvalInspection->id, $rejectionInspection->id];

        DB::table('apparatus_inspection_review_events')
            ->whereIn('apparatus_inspection_id', $inspectionIds)
            ->delete();
        DB::table('apparatus_defects')
            ->whereIn('apparatus_inspection_id', $inspectionIds)
            ->delete();
        DB::table('apparatus_inspections')->whereIn('id', $inspectionIds)->delete();
        // A critical approval can move this fixture apparatus to OOS, which
        // intentionally emits a restrictive status-ledger event. Remove only
        // that disposable fixture evidence before deleting the fixture rig.
        DB::table('apparatus_operational_status_events')
            ->where('apparatus_id', $apparatus->id)
            ->delete();
        DB::table('apparatuses')->where('id', $apparatus->id)->delete();
        DB::table('stations')->where('id', $station->id)->delete();
        DB::table('users')->where('id', $reviewer->id)->delete();
    }
}
