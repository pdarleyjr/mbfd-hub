<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\InspectionMeterBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class InspectionProcessingPostgresLockTest extends TestCase
{
    public function test_concurrent_meter_commit_is_rechecked_after_the_apparatus_row_lock(): void
    {
        if (getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1' || DB::connection()->getDriverName() !== 'pgsql') {
            if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
                $this->fail('The guarded disposable PostgreSQL database is required.');
            }
            $this->markTestSkipped('Requires the guarded disposable PostgreSQL database.');
        }
        Http::preventStrayRequests();
        Queue::fake();
        $apparatus = Apparatus::create(['name' => 'Concurrent inspection fixture', 'unit_id' => 'PG-CHECK-'.uniqid(), 'type' => 'Engine', 'status' => 'In Service', 'current_engine_hours' => 100, 'current_miles' => 1000]);
        $inspection = ApparatusInspection::create(['apparatus_id' => $apparatus->id, 'operator_name' => 'Concurrency fixture', 'rank' => 'Firefighter', 'review_status' => 'pending_review', 'engine_hours' => 101, 'miles' => 1001, 'results' => []]);
        $token = app(InspectionMeterBaseline::class)->issue($apparatus);
        $connection = config('database.connections.pgsql');
        $lockKey = random_int(1_000_000, 2_000_000_000);
        $writer = new Process([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', <<<'PHP'
$pdo = new PDO(getenv('CHECKOUT_PG_DSN'), getenv('CHECKOUT_PG_USER'), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$statement = $pdo->prepare('UPDATE apparatuses SET current_engine_hours = 102, current_miles = 1002 WHERE id = :id');
$statement->execute(['id' => (int) getenv('CHECKOUT_APPARATUS_ID')]);
if ($statement->rowCount() !== 1) throw new RuntimeException('Fixture missing');
$pdo->query('SELECT pg_advisory_xact_lock('.(int) getenv('CHECKOUT_LOCK_KEY').')');
$pdo->query('SELECT pg_sleep(0.6)');
$pdo->commit();
PHP], base_path(), [
            'CHECKOUT_PG_DSN' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            'CHECKOUT_PG_USER' => $connection['username'],
            'CHECKOUT_APPARATUS_ID' => (string) $apparatus->id,
            'CHECKOUT_LOCK_KEY' => (string) $lockKey,
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ]);
        try {
            $writer->start();
            $deadline = microtime(true) + 5;
            do {
                $lock = DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$lockKey]);
                $acquired = filter_var($lock?->acquired, FILTER_VALIDATE_BOOL);
                if (! $acquired) {
                    break;
                }
                DB::selectOne('SELECT pg_advisory_unlock(?)', [$lockKey]);
                if (! $writer->isRunning()) {
                    $this->fail('Concurrent writer exited early: '.$writer->getErrorOutput());
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertFalse($acquired, 'The independent writer must hold its row before processing starts.');

            DB::transaction(function () use ($apparatus, $inspection, $token): void {
                $locked = Apparatus::query()->lockForUpdate()->findOrFail($apparatus->id);
                app(ApparatusInspectionProcessingService::class)->process($inspection, $locked, $token);
            });
            $writer->wait();
            $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            $this->assertSame(1002, $apparatus->fresh()->current_miles);
            $this->assertEquals(102, $apparatus->fresh()->current_engine_hours);
            $this->assertSame(1001, $inspection->fresh()->miles);
            $this->assertSame('accepted_with_exception', $inspection->fresh()->processing_status);
            $this->assertSame(2, DB::table('apparatus_inspection_exceptions')->where('apparatus_inspection_id', $inspection->id)->where('reason', 'stale_baseline')->count());
            $this->assertSame(1, DB::table('apparatus_inspection_review_events')->where('apparatus_inspection_id', $inspection->id)->count());
            Queue::assertNothingPushed();
        } finally {
            if ($writer->isRunning()) {
                $writer->wait();
            }
            DB::table('apparatus_inspection_exceptions')->where('apparatus_inspection_id', $inspection->id)->delete();
            DB::table('apparatus_inspection_review_events')->where('apparatus_inspection_id', $inspection->id)->delete();
            DB::table('apparatus_inspections')->where('id', $inspection->id)->delete();
            DB::table('apparatuses')->where('id', $apparatus->id)->delete();
        }
    }
}
