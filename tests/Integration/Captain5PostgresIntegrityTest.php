<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\Station;
use App\Models\User;
use App\Services\Captain5\Captain5ActorContext;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Security\Captain5Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * Captain 5 integrity on real PostgreSQL: the idempotency key and self reference of acting
 * Captain 5 requests, the partial unique index that keeps one current override per member, and the shared corrective-action
 * engine's lock retry when a Captain 5 acts while another session holds the issue row.
 * Guarded to an explicitly configured disposable test database (never production).
 */
#[Group('postgres')]
final class Captain5PostgresIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        Http::preventStrayRequests();
    }

    public function test_partial_unique_indexes_hold_on_postgresql(): void
    {
        $this->requireDisposablePostgres();
        $suffix = strtoupper(substr(uniqid(), -6));
        $actor = User::factory()->create(['account_status' => AccountStatus::Active]);
        $first = Employee::query()->create(['employee_id' => "C5PG-A-{$suffix}", 'name' => 'PG One', 'rank' => 'Lieutenant', 'password' => 'x']);
        $second = Employee::query()->create(['employee_id' => "C5PG-B-{$suffix}", 'name' => 'PG Two', 'rank' => 'Lieutenant', 'password' => 'x']);
        $key = 'c5pg-'.strtolower($suffix);
        $row = fn (Employee $employee, string $clientId): array => [
            'kind' => Captain5ActingRequest::KIND_ACT, 'employee_profile_id' => $employee->id, 'requested_by_user_id' => $actor->id,
            'duration' => '24h', 'shift_letter' => 'A', 'ends_at' => CarbonImmutable::parse('2099-01-02 13:00:00', 'UTC'),
            'status' => Captain5ActingRequest::PENDING, 'client_request_id' => $clientId,
        ];

        try {
            $active = Captain5ActingRequest::query()->create($row($first, $key));
            try {
                DB::transaction(fn () => Captain5ActingRequest::query()->create($row($second, $key)));
                $this->fail('A retried acting request must violate the client_request_id unique index.');
            } catch (UniqueConstraintViolationException) {
            }
            $replacement = Captain5ActingRequest::query()->create($row($second, $key.'-2'));
            $active->forceFill(['ended_at' => now(), 'ended_by_request_id' => $replacement->id])->save();
            $this->assertSame($replacement->id, (int) $active->fresh()->ended_by_request_id, 'A takeover records which request ended the earlier one');

            Captain5AccessOverride::query()->create(['employee_profile_id' => $first->id, 'effect' => 'deny', 'reason' => 'PG', 'set_by_user_id' => $actor->id]);
            try {
                DB::transaction(fn () => Captain5AccessOverride::query()->create(['employee_profile_id' => $first->id, 'effect' => 'allow', 'level' => 'view', 'reason' => 'PG', 'set_by_user_id' => $actor->id]));
                $this->fail('Two current overrides for one member must violate the partial unique index.');
            } catch (UniqueConstraintViolationException) {
            }
        } finally {
            DB::table('captain5_access_overrides')->whereIn('employee_profile_id', [$first->id, $second->id])->delete();
            DB::table('captain5_acting_requests')->whereIn('employee_profile_id', [$first->id, $second->id])->update(['ended_by_request_id' => null]);
            DB::table('captain5_acting_requests')->whereIn('employee_profile_id', [$first->id, $second->id])->delete();
            DB::table('employees')->whereIn('id', [$first->id, $second->id])->delete();
            DB::table('users')->where('id', $actor->id)->delete();
        }
    }

    public function test_a_captain5_action_waits_for_a_held_issue_lock_and_a_late_duplicate_is_a_no_op(): void
    {
        $this->requireDisposablePostgres();
        Notification::fake();
        $suffix = strtoupper(substr(uniqid(), -6));
        $station = Station::query()->create(['station_number' => random_int(900_000, 999_999), 'name' => "C5 Lock {$suffix}", 'address' => '5 Test Way', 'is_active' => true]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => "R{$suffix}", 'name' => 'Rescue Lock', 'type' => 'Rescue',
            'vehicle_number' => "C5L-{$suffix}", 'designation' => "R{$suffix}", 'slug' => "rescue-lock-{$suffix}", 'status' => 'In Service',
            'daily_checkout_template' => 'rescue',
        ]);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id, 'processed_apparatus_id' => $apparatus->id, 'operator_name' => 'PG Tester', 'rank' => 'Firefighter',
            'shift' => 'A', 'unit_number' => $apparatus->designation, 'inspection_reference' => "INS-C5-{$suffix}",
            'review_status' => 'approved', 'processing_status' => 'accepted', 'completed_at' => now(),
        ]);
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id, 'apparatus_inspection_id' => $inspection->id, 'first_reported_inspection_id' => $inspection->id,
            'compartment' => 'L2', 'item' => "Lock item {$suffix}", 'status' => 'open', 'issue_type' => 'missing',
        ]);
        $employee = Employee::query()->create(['employee_id' => "C5PG-C-{$suffix}", 'name' => 'PG Captain', 'rank' => 'Captain', 'password' => 'x']);
        $captain = User::factory()->create(['account_status' => AccountStatus::Active, 'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        $seat = EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $employee->id, 'payload_version' => 2, 'bid_year' => 2099, 'term_label' => '2099–2100',
            'bid_session_id' => 'pg', 'position_id' => "PG{$suffix}", 'rank_label' => 'Captain', 'shift_label' => 'B Shift',
            'station_label' => 'Station 2', 'unit_label' => 'Captain 5', 'position_label' => 'Captain 5', 'bid_selection_label' => 'Captain 5',
            'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award', 'a_day_label' => 'A-Day', 'idempotency_key' => "pg-{$suffix}",
            'payload_hash' => str_repeat('a', 64), 'is_forced' => false, 'source_sequence' => 1,
            'source_result_hash' => str_repeat('b', 64), 'source_workbook_sha256' => str_repeat('c', 64),
        ]);
        $connection = DB::connection();
        $lockHolder = null;

        try {
            $decision = app(Captain5Access::class)->decide($captain->fresh());
            $this->assertTrue($decision->canAct, 'The PG fixture seat must qualify');
            $engine = app(DefectCorrectiveActionService::class)->forCaptain5(Captain5ActorContext::from($decision));

            $lockHolder = $this->holdDefectLock($defect->id);
            $this->waitUntilLocked($lockHolder);
            $connection->statement("SET lock_timeout = '75ms'");
            $engine->acknowledge($defect->id, $captain, [], 'pg-c5-a');
            $connection->statement('SET lock_timeout = DEFAULT');
            $lockHolder->wait();
            $this->assertTrue($lockHolder->isSuccessful(), $lockHolder->getErrorOutput());

            $engine->acknowledge($defect->id, $captain, [], 'pg-c5-a'); // network retry
            $this->assertSame(1, DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->where('event_type', 'acknowledged')->count());
            $this->assertSame('acknowledged', $defect->fresh()->action_status);
            $details = json_decode((string) DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->value('details'), true);
            $this->assertSame('captain5', $details['actor_context']['role'] ?? null);
        } finally {
            $connection->statement('SET lock_timeout = DEFAULT');
            if ($lockHolder instanceof SymfonyProcess && $lockHolder->isRunning()) {
                $lockHolder->wait();
            }
            DB::table('inspection_notification_deliveries')->where('recipient_user_id', $captain->id)->delete();
            DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->delete();
            DB::table('apparatus_issue_messages')->where('apparatus_defect_id', $defect->id)->delete();
            DB::table('apparatus_defect_participants')->where('apparatus_defect_id', $defect->id)->delete();
            DB::table('apparatus_defects')->where('id', $defect->id)->delete();
            DB::table('apparatus_inspections')->where('id', $inspection->id)->delete();
            DB::table('apparatus_operational_status_events')->where('apparatus_id', $apparatus->id)->delete();
            DB::table('apparatuses')->where('id', $apparatus->id)->delete();
            DB::table('stations')->where('id', $station->id)->delete();
            DB::table('employee_bid_assignments')->where('id', $seat->id)->delete();
            DB::table('users')->where('id', $captain->id)->delete();
            DB::table('employees')->where('id', $employee->id)->delete();
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

    private function holdDefectLock(int $defectId): SymfonyProcess
    {
        $connection = config('database.connections.'.config('database.default'));
        $process = new SymfonyProcess([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', <<<'PHP'
$pdo = new PDO(getenv('AUDIT_PG_DSN'), getenv('AUDIT_PG_USERNAME'), getenv('AUDIT_PG_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$row = $pdo->prepare('SELECT id FROM apparatus_defects WHERE id = :id FOR UPDATE');
$row->execute(['id' => (int) getenv('AUDIT_PG_DEFECT_ID')]);
fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
$pdo->query('SELECT pg_sleep(0.6)');
$pdo->commit();
PHP], base_path(), [
            'AUDIT_PG_DSN' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            'AUDIT_PG_DEFECT_ID' => (string) $defectId,
            'AUDIT_PG_PASSWORD' => (string) $connection['password'],
            'AUDIT_PG_USERNAME' => (string) $connection['username'],
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ]);
        $process->start();

        return $process;
    }

    private function waitUntilLocked(SymfonyProcess $lockHolder): void
    {
        $deadline = microtime(true) + 5;
        $output = '';
        do {
            $output .= $lockHolder->getIncrementalOutput();
            if (str_contains($output, 'LOCKED')) {
                return;
            }
            if (! $lockHolder->isRunning()) {
                $this->fail('The lock holder exited before it locked the row: '.$lockHolder->getErrorOutput());
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out waiting for the lock holder.');
    }
}
