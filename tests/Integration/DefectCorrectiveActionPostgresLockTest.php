<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use App\Services\Defects\DefectCorrectiveActionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * A separate local PHP process holds the defect row the corrective-action service
 * locks. The service must retry PostgreSQL lock timeouts (55P03), then a second
 * administrator resolving the same issue must leave exactly one resolved event.
 *
 * Deliberately guarded to an explicitly configured disposable test database.
 */
#[Group('postgres')]
final class DefectCorrectiveActionPostgresLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('google_sheets.apparatus_sync_enabled', false);
        Http::preventStrayRequests();
    }

    public function test_postgresql_retries_a_defect_lock_and_two_administrators_leave_one_resolved_event(): void
    {
        $this->requireDisposablePostgres();
        Notification::fake();

        $suffix = strtoupper(substr(uniqid(), -6));
        $station = Station::query()->create([
            'station_number' => random_int(900_000, 999_999),
            'name' => "Defect Lock {$suffix}",
            'address' => '902 Test Way',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => "E-LOCK-{$suffix}",
            'name' => 'Engine Defect Lock',
            'type' => 'Engine',
            'vehicle_number' => "LOCK-{$suffix}",
            'designation' => "E{$suffix}",
            'slug' => "engine-defect-lock-{$suffix}",
            'status' => 'In Service',
            'current_engine_hours' => 10.0,
            'current_miles' => 1000,
        ]);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'processed_apparatus_id' => $apparatus->id,
            'operator_name' => 'PostgreSQL Defect Lock Tester',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $apparatus->designation,
            'inspection_reference' => "INS-LOCK-{$suffix}",
            'review_status' => 'approved',
            'processing_status' => 'accepted',
            'completed_at' => now(),
        ]);
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $adminOne = User::factory()->create(['account_status' => AccountStatus::Active]);
        $adminTwo = User::factory()->create(['account_status' => AccountStatus::Active]);
        $role = Role::findOrCreate('logistics_admin', 'web');
        $adminOne->assignRole($role);
        $adminTwo->assignRole($role);
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'apparatus_inspection_id' => $inspection->id,
            'compartment' => 'Cab',
            'item' => "Lock fixture item {$suffix}",
            'status' => 'open',
            'issue_type' => 'missing',
            'first_reported_by_user_id' => $reporter->id,
        ]);
        ApparatusDefectParticipant::query()->create([
            'apparatus_defect_id' => $defect->id,
            'user_id' => $reporter->id,
            'enrollment_source' => 'reported',
            'first_participated_at' => now(),
            'last_participated_at' => now(),
        ]);
        $connection = DB::connection();
        $lockHolder = null;

        try {
            $service = app(DefectCorrectiveActionService::class);
            $lockHolder = $this->holdDefectLockInSeparateProcess($defect->id);
            $this->waitUntilLockIsHeld($lockHolder);

            $connection->statement("SET lock_timeout = '75ms'");
            $startedAt = hrtime(true);
            $resolved = $service->resolve($defect->id, $adminOne, [
                'resolution_type' => 'replacement_installed',
                'resolution_notes' => 'Resolved while another session held the row.',
            ], 'pg-lock-a');
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            $connection->statement('SET lock_timeout = DEFAULT');

            $lockHolder->wait();
            $this->assertTrue($lockHolder->isSuccessful(), $lockHolder->getErrorOutput());
            $this->assertGreaterThanOrEqual(0.25, $elapsedSeconds);
            $this->assertTrue($resolved->resolved);

            $second = $service->resolve($defect->id, $adminTwo, [
                'resolution_type' => 'other',
                'resolution_notes' => 'A second administrator arrived late.',
            ], 'pg-lock-b');

            $this->assertSame($adminOne->id, $second->resolved_by_user_id);
            $this->assertSame(1, DB::table('apparatus_defect_events')
                ->where('apparatus_defect_id', $defect->id)
                ->where('event_type', 'resolved')
                ->count());
            $this->assertSame(1, DB::table('inspection_notification_deliveries')
                ->where('recipient_user_id', $reporter->id)
                ->count());
        } finally {
            $connection->statement('SET lock_timeout = DEFAULT');
            if ($lockHolder instanceof SymfonyProcess && $lockHolder->isRunning()) {
                $lockHolder->wait();
            }

            DB::table('inspection_notification_deliveries')->whereIn('recipient_user_id', [$reporter->id, $adminOne->id, $adminTwo->id])->delete();
            DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->delete();
            DB::table('apparatus_issue_messages')->where('apparatus_defect_id', $defect->id)->delete();
            DB::table('apparatus_defect_participants')->where('apparatus_defect_id', $defect->id)->delete();
            DB::table('apparatus_defects')->where('id', $defect->id)->delete();
            DB::table('apparatus_inspections')->where('id', $inspection->id)->delete();
            DB::table('apparatus_operational_status_events')->where('apparatus_id', $apparatus->id)->delete();
            DB::table('apparatuses')->where('id', $apparatus->id)->delete();
            DB::table('stations')->where('id', $station->id)->delete();
            DB::table('users')->whereIn('id', [$reporter->id, $adminOne->id, $adminTwo->id])->delete();
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

    private function holdDefectLockInSeparateProcess(int $defectId): SymfonyProcess
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
$row = $pdo->prepare('SELECT id FROM apparatus_defects WHERE id = :id FOR UPDATE');
$row->execute(['id' => (int) getenv('AUDIT_PG_DEFECT_ID')]);
if ($row->fetchColumn() === false) {
    throw new RuntimeException('The disposable PostgreSQL lock fixture was not found.');
}
fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
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
            'AUDIT_PG_DEFECT_ID' => (string) $defectId,
            'AUDIT_PG_PASSWORD' => (string) $connection['password'],
            'AUDIT_PG_USERNAME' => (string) $connection['username'],
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ]);
        $process->start();

        return $process;
    }

    private function waitUntilLockIsHeld(SymfonyProcess $lockHolder): void
    {
        $deadline = microtime(true) + 5;
        $output = '';

        do {
            $output .= $lockHolder->getIncrementalOutput();
            if (str_contains($output, 'LOCKED')) {
                return;
            }
            if (! $lockHolder->isRunning()) {
                $this->fail('The disposable PostgreSQL lock holder exited before it locked the row: '.$lockHolder->getErrorOutput());
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for the disposable PostgreSQL lock holder.');
    }
}
