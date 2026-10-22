<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\User;
use App\Services\Inspections\InspectionReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * A separate local PHP process holds the held inspection's row lock. The review
 * service must retry the PostgreSQL lock timeout, and two reviewers deciding the
 * same inspection must leave exactly one terminal approval event.
 *
 * Guarded to an explicitly configured disposable test database.
 */
#[Group('postgres')]
final class InspectionReviewPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        Http::preventStrayRequests();
    }

    public function test_two_reviewers_approving_a_held_inspection_leave_one_terminal_event(): void
    {
        $this->requireDisposablePostgres();
        Queue::fake();
        $suffix = strtoupper(substr(uniqid(), -6));
        $apparatus = Apparatus::query()->create([
            'unit_id' => "E-REV-{$suffix}", 'name' => "Engine Review {$suffix}", 'type' => 'Engine', 'status' => 'In Service',
            'vehicle_number' => "REV-{$suffix}", 'designation' => "ER{$suffix}", 'slug' => "engine-review-{$suffix}",
            'current_engine_hours' => 100, 'current_miles' => 1000,
        ]);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id, 'processed_apparatus_id' => $apparatus->id, 'operator_name' => 'Review race', 'rank' => 'Firefighter',
            'review_status' => 'pending_review', 'processing_status' => 'held_for_review', 'results' => [],
            'review_flag_explanation' => 'Look at the pump panel.', 'completed_at' => now(),
        ]);
        ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_id' => $apparatus->id, 'field' => 'member_review_flag',
            'reason' => 'member_flagged', 'blocks_acceptance' => true, 'metadata' => ['explanation' => 'Look at the pump panel.', 'items' => []],
        ]);
        // Real reviewers: active accounts (InspectionReviewers::canReview) holding the super_admin role.
        $first = User::factory()->create(['account_status' => AccountStatus::Active]);
        $first->assignRole(Role::findOrCreate('super_admin', 'web'));
        $second = User::factory()->create(['account_status' => AccountStatus::Active]);
        $second->assignRole(Role::findOrCreate('super_admin', 'web'));
        $connection = DB::connection();
        $holder = null;

        try {
            $lockKey = random_int(1_000_000, 2_000_000_000);
            $holder = $this->holdInspectionLock($inspection->id, $lockKey);
            $this->waitUntilAdvisoryLockIsHeld($lockKey, $holder);

            $connection->statement("SET lock_timeout = '75ms'");
            $startedAt = hrtime(true);
            $approved = app(InspectionReviewService::class)->approve($inspection->id, $first, 'First reviewer.');
            $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
            $connection->statement('SET lock_timeout = DEFAULT');
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            $this->assertGreaterThanOrEqual(0.25, $elapsed, 'The service must have retried the lock timeout.');
            $this->assertSame('approved', $approved->review_status);

            $again = app(InspectionReviewService::class)->approve($inspection->id, $second, 'Second reviewer.');
            $rejected = app(InspectionReviewService::class)->reject($inspection->id, $second, 'A later conflicting decision.');

            $this->assertSame('approved', $again->review_status);
            $this->assertSame('approved', $rejected->review_status);
            $this->assertSame('accepted', $inspection->fresh()->processing_status);
            $this->assertSame(1, DB::table('apparatus_inspection_review_events')->where('apparatus_inspection_id', $inspection->id)->where('status', 'approved')->count());
            $this->assertSame(0, DB::table('apparatus_inspection_review_events')->where('apparatus_inspection_id', $inspection->id)->where('status', 'rejected')->count());
            $this->assertSame('resolved', DB::table('apparatus_inspection_exceptions')->where('apparatus_inspection_id', $inspection->id)->value('status'));
        } finally {
            $connection->statement('SET lock_timeout = DEFAULT');
            if ($holder instanceof SymfonyProcess && $holder->isRunning()) {
                $holder->wait();
            }
            DB::table('apparatus_inspection_exceptions')->where('apparatus_inspection_id', $inspection->id)->delete();
            DB::table('apparatus_inspection_review_events')->where('apparatus_inspection_id', $inspection->id)->delete();
            DB::table('apparatus_inspections')->where('id', $inspection->id)->delete();
            DB::table('apparatuses')->where('id', $apparatus->id)->delete();
            DB::table('users')->whereIn('id', [$first->id, $second->id])->delete();
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

    private function holdInspectionLock(int $inspectionId, int $lockKey): SymfonyProcess
    {
        $connection = config('database.connections.'.config('database.default'));
        $process = new SymfonyProcess([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', <<<'PHP'
$pdo = new PDO(getenv('REVIEW_PG_DSN'), getenv('REVIEW_PG_USERNAME'), getenv('REVIEW_PG_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$inspection = $pdo->prepare('SELECT id FROM apparatus_inspections WHERE id = :id FOR UPDATE');
$inspection->execute(['id' => (int) getenv('REVIEW_PG_INSPECTION_ID')]);
if ($inspection->fetchColumn() === false) {
    throw new RuntimeException('The disposable PostgreSQL lock fixture was not found.');
}
$pdo->query('SELECT pg_advisory_xact_lock('.(int) getenv('REVIEW_PG_LOCK_KEY').')');
$pdo->query('SELECT pg_sleep(0.6)');
$pdo->commit();
PHP], base_path(), [
            'REVIEW_PG_DSN' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            'REVIEW_PG_INSPECTION_ID' => (string) $inspectionId,
            'REVIEW_PG_LOCK_KEY' => (string) $lockKey,
            'REVIEW_PG_PASSWORD' => (string) $connection['password'],
            'REVIEW_PG_USERNAME' => (string) $connection['username'],
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ]);
        $process->start();

        return $process;
    }

    private function waitUntilAdvisoryLockIsHeld(int $lockKey, SymfonyProcess $holder): void
    {
        $deadline = microtime(true) + 5;
        do {
            $result = DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$lockKey]);
            if (! filter_var($result?->acquired, FILTER_VALIDATE_BOOL)) {
                return;
            }
            DB::selectOne('SELECT pg_advisory_unlock(?)', [$lockKey]);
            if (! $holder->isRunning()) {
                $this->fail('The disposable PostgreSQL lock holder exited before it acquired the fixture lock: '.$holder->getErrorOutput());
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out waiting for the disposable PostgreSQL lock holder.');
    }
}
