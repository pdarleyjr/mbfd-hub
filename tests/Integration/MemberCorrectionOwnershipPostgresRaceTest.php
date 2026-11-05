<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * POST /api/public/apparatus-issues/{defect}/member-correction (DefectMemberCorrection::report) racing, on real
 * PostgreSQL, with an owner's transfer (DefectOwnership::transfer) and with a second member's identical "fixed".
 *
 * A separate PHP process holds the defect row lock while the racers queue on it in a known order (PostgreSQL grants
 * row locks in arrival order), so both orders are exercised without sleeps inside the code under test. Each racer is a
 * real PHP process that boots the application and calls the same service the controller calls.
 *
 * Invariants asserted, whichever racer wins:
 *  - a routine issue is closed by exactly one member_resolved event; the loser sees ISSUE_CLOSED (409), never a second event
 *  - a transfer that loses to a closing member is refused ("closed") and writes no owner_transferred event; one that wins
 *    leaves exactly one owner_transferred event and the owner/captain5_cleared_at pair the previous image still reads
 *  - a non-routine issue ("damaged") records one correction_reported and one transfer in either order; nothing is lost
 *    and the pending marker survives the transfer
 *  - the same client_action_id sent twice at once yields one event and the same result
 *
 * Guarded to an explicitly configured disposable test database (never production).
 */
#[Group('postgres')]
final class MemberCorrectionOwnershipPostgresRaceTest extends TestCase
{
    /** @var list<int> */
    private array $defectIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?Apparatus $apparatus = null;

    private ?ApparatusInspection $inspection = null;

    private ?Station $station = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        Http::preventStrayRequests();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        if ($this->defectIds !== []) {
            DB::table('inspection_notification_deliveries')->whereIn('recipient_user_id', $this->userIds)->delete();
            DB::table('apparatus_defect_events')->whereIn('apparatus_defect_id', $this->defectIds)->delete();
            DB::table('apparatus_issue_messages')->whereIn('apparatus_defect_id', $this->defectIds)->delete();
            DB::table('apparatus_defect_participants')->whereIn('apparatus_defect_id', $this->defectIds)->delete();
            DB::table('apparatus_defects')->whereIn('id', $this->defectIds)->delete();
        }
        if ($this->inspection !== null) {
            DB::table('apparatus_inspections')->where('id', $this->inspection->id)->delete();
        }
        if ($this->apparatus !== null) {
            DB::table('apparatus_operational_status_events')->where('apparatus_id', $this->apparatus->id)->delete();
            DB::table('apparatuses')->where('id', $this->apparatus->id)->delete();
        }
        if ($this->station !== null) {
            DB::table('stations')->where('id', $this->station->id)->delete();
        }
        if ($this->userIds !== []) {
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> first racer, second racer (arrival order at the row lock) */
    public static function orders(): array
    {
        return ['member first, transfer second' => ['member', 'transfer'], 'transfer first, member second' => ['transfer', 'member']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_a_routine_captain5_issue_closed_by_a_member_races_an_owner_transfer(string $first, string $second): void
    {
        $this->requireDisposablePostgres();
        [$member, $logistics] = $this->users(2);
        $defect = $this->defect(routine: true);
        $holder = $this->holdDefectLock($defect->id);
        $this->waitUntilLocked($holder);

        $racers = $this->start([
            $first => $this->job($first, $defect->id, $member->id, $logistics->id, 'a'),
            $second => $this->job($second, $defect->id, $member->id, $logistics->id, 'b'),
        ]);
        $results = $this->finish($holder, $racers);

        $events = DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id);
        $resolved = (clone $events)->where('event_type', 'member_resolved')->count();
        $transfers = (clone $events)->where('event_type', 'owner_transferred')->count();
        $row = DB::table('apparatus_defects')->where('id', $defect->id)->first();

        $this->assertSame(1, $resolved, 'The member closes the routine issue exactly once: '.json_encode($results));
        $this->assertSame('resolved', $row->action_status);
        $this->assertTrue((bool) $row->resolved);
        $this->assertSame('resolved', $results['member']['result'] ?? null);

        if ($first === 'transfer') {
            $this->assertSame('transferred', $results['transfer']['result'], json_encode($results));
            $this->assertSame(1, $transfers);
            $this->assertSame('logistics', $row->responsible_owner);
            $this->assertSame('manual', $row->owner_basis);
            $this->assertNotNull($row->captain5_cleared_at, 'The previous image still reads the clear');
        } else {
            $this->assertSame('refused', $results['transfer']['result'], 'A closed issue cannot be transferred: '.json_encode($results));
            $this->assertSame(0, $transfers, 'A refused transfer leaves no event');
            $this->assertSame('captain5', $row->responsible_owner);
            $this->assertNull($row->captain5_cleared_at);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_a_damaged_issue_keeps_both_the_correction_report_and_the_transfer(string $first, string $second): void
    {
        $this->requireDisposablePostgres();
        [$member, $logistics] = $this->users(2);
        $defect = $this->defect(routine: false);
        $holder = $this->holdDefectLock($defect->id);
        $this->waitUntilLocked($holder);

        $racers = $this->start([
            $first => $this->job($first, $defect->id, $member->id, $logistics->id, 'a'),
            $second => $this->job($second, $defect->id, $member->id, $logistics->id, 'b'),
        ]);
        $results = $this->finish($holder, $racers);

        $row = DB::table('apparatus_defects')->where('id', $defect->id)->first();
        $this->assertSame('awaiting_verification', $results['member']['result'] ?? null, json_encode($results));
        $this->assertSame('transferred', $results['transfer']['result'] ?? null, json_encode($results));
        $this->assertSame(1, DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->where('event_type', 'correction_reported')->count());
        $this->assertSame(1, DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->where('event_type', 'owner_transferred')->count());
        $this->assertFalse((bool) $row->resolved, 'A non-routine issue is never closed by a member');
        $this->assertNotNull($row->correction_reported_at, 'The pending marker survives the transfer');
        $this->assertSame('logistics', $row->responsible_owner);
        $this->assertSame('manual', $row->owner_basis);
        $this->assertNotNull($row->captain5_cleared_at);
    }

    public function test_two_members_at_once_close_a_routine_issue_once_and_the_loser_is_told_it_is_closed(): void
    {
        $this->requireDisposablePostgres();
        [$one, $two] = $this->users(2);
        $defect = $this->defect(routine: true);
        $holder = $this->holdDefectLock($defect->id);
        $this->waitUntilLocked($holder);

        $racers = $this->start([
            'one' => $this->job('member', $defect->id, $one->id, $one->id, 'a'),
            'two' => $this->job('member', $defect->id, $two->id, $two->id, 'b'),
        ]);
        $results = $this->finish($holder, $racers);

        $outcomes = collect($results)->pluck('result')->sort()->values()->all();
        $this->assertSame(['refused', 'resolved'], $outcomes, json_encode($results));
        $this->assertSame('ISSUE_CLOSED', collect($results)->firstWhere('result', 'refused')['code'] ?? null);
        $this->assertSame(1, DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->where('event_type', 'member_resolved')->count());
    }

    public function test_the_same_client_action_id_sent_twice_at_once_is_one_event_and_one_answer(): void
    {
        $this->requireDisposablePostgres();
        [$member] = $this->users(1);
        $defect = $this->defect(routine: true);
        $holder = $this->holdDefectLock($defect->id);
        $this->waitUntilLocked($holder);

        $racers = $this->start([
            'first' => $this->job('member', $defect->id, $member->id, $member->id, 'same'),
            'second' => $this->job('member', $defect->id, $member->id, $member->id, 'same'),
        ]);
        $results = $this->finish($holder, $racers);

        $this->assertSame('resolved', $results['first']['result'] ?? null, json_encode($results));
        $this->assertSame('resolved', $results['second']['result'] ?? null, 'The retry gets the first outcome, not a 409: '.json_encode($results));
        $this->assertSame(1, DB::table('apparatus_defect_events')->where('apparatus_defect_id', $defect->id)->where('event_type', 'member_resolved')->count());
    }

    // ------------------------------------------------------------------ fixtures

    /** @return list<User> */
    private function users(int $count): array
    {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create(['account_status' => AccountStatus::Active]);
            $this->userIds[] = $user->id;
            $users[] = $user;
        }

        return $users;
    }

    private function defect(bool $routine): ApparatusDefect
    {
        $suffix = strtoupper(substr(uniqid(), -6));
        $this->station ??= Station::query()->create(['station_number' => random_int(900_000, 999_999), 'name' => "Member Race {$suffix}", 'address' => '7 Test Way', 'is_active' => true]);
        $this->apparatus ??= Apparatus::query()->create([
            'station_id' => $this->station->id, 'unit_id' => "R{$suffix}", 'name' => 'Rescue Race', 'type' => 'Rescue',
            'vehicle_number' => "MR-{$suffix}", 'designation' => "R{$suffix}", 'slug' => "rescue-race-{$suffix}", 'status' => 'In Service',
            'daily_checkout_template' => 'rescue',
        ]);
        $this->inspection ??= ApparatusInspection::query()->create([
            'apparatus_id' => $this->apparatus->id, 'processed_apparatus_id' => $this->apparatus->id, 'operator_name' => 'PG Race', 'rank' => 'Firefighter',
            'shift' => 'A', 'unit_number' => $this->apparatus->designation, 'inspection_reference' => "INS-MR-{$suffix}",
            'review_status' => 'approved', 'processing_status' => 'accepted', 'completed_at' => now(),
        ]);
        // Rescue LifePak 15: a medical item, so the created observer projects it to Captain 5.
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $this->apparatus->id, 'apparatus_inspection_id' => $this->inspection->id, 'first_reported_inspection_id' => $this->inspection->id,
            'compartment' => 'Patient Compartment', 'item' => "LifePak 15 {$suffix}", 'compartment_key' => 'patient_compartment', 'item_key' => 'patient_compartment-item-5',
            'status' => 'open', 'issue_type' => $routine ? 'missing' : 'damaged', 'severity' => 'routine',
        ]);
        $this->defectIds[] = $defect->id;
        $this->assertSame('captain5', $defect->fresh()->responsible_owner, 'Fixture: the observer projected the medical item to Captain 5');

        return $defect;
    }

    // ------------------------------------------------------------------ racers

    /** @return array{kind: string, defect: int, member: int, logistics: int, action: string} */
    private function job(string $kind, int $defectId, int $memberId, int $logisticsId, string $actionSuffix): array
    {
        return ['kind' => $kind, 'defect' => $defectId, 'member' => $memberId, 'logistics' => $logisticsId, 'action' => '00000000-0000-4000-8000-'.str_pad(bin2hex($actionSuffix), 12, '0', STR_PAD_LEFT)];
    }

    /**
     * @param  array<string, array<string, mixed>>  $jobs  insertion order is arrival order at the lock
     * @return array<string, SymfonyProcess>
     */
    private function start(array $jobs): array
    {
        $processes = [];
        $queued = 0;
        foreach ($jobs as $name => $job) {
            $process = new SymfonyProcess([PHP_BINARY, '-r', self::WORKER], base_path(), $this->workerEnv() + ['RACE_JOB' => json_encode($job)]);
            $process->start();
            $processes[$name] = $process;
            $queued++;
            // Do not start the next racer until this one is really queued on the row lock: the arrival order is then fixed.
            $deadline = microtime(true) + 8;
            while ($this->lockWaiters() < $queued) {
                if (microtime(true) > $deadline || ! $process->isRunning()) {
                    $this->fail("Racer {$name} never queued on the row lock. stderr: ".$process->getErrorOutput().' stdout: '.$process->getOutput());
                }
                usleep(25_000);
            }
        }

        return $processes;
    }

    private function lockWaiters(): int
    {
        return (int) DB::selectOne("select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and query ilike '%apparatus_defects%'")->n;
    }

    /**
     * @param  array<string, SymfonyProcess>  $racers
     * @return array<string, array<string, mixed>>
     */
    private function finish(SymfonyProcess $holder, array $racers): array
    {
        $holder->wait();
        $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
        $results = [];
        foreach ($racers as $name => $process) {
            $process->wait();
            $decoded = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($decoded, "Racer {$name} printed no result. stderr: ".$process->getErrorOutput());
            $results[$name] = $decoded;
        }

        return $results;
    }

    /** @return array<string, string> */
    private function workerEnv(): array
    {
        $c = config('database.connections.'.config('database.default'));

        return [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $c['host'], 'DB_PORT' => (string) $c['port'],
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) $c['password'],
            'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MBFD_ALLOW_DISPOSABLE_POSTGRES' => '1', 'EXPECTED_TEST_DB_CONNECTION' => 'pgsql',
        ];
    }

    private const WORKER = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('google_sheets.apparatus_sync_enabled', false);
Illuminate\Support\Facades\Notification::fake();
$job = json_decode((string) getenv('RACE_JOB'), true);
$out = [];
try {
    if ($job['kind'] === 'member') {
        $result = app(App\Services\Defects\DefectMemberCorrection::class)->report(
            App\Models\User::findOrFail($job['member']), $job['defect'], ['outcome' => 'found', 'note' => null], $job['action']);
        $out = ['result' => $result->toArray()['result'] ?? 'unknown'];
    } else {
        app(App\Services\Defects\DefectOwnership::class)->transfer(
            App\Models\User::findOrFail($job['logistics']), $job['defect'], 'logistics', null, 'race-'.$job['action'], 'race', ['role' => 'fleet_reviewer']);
        $out = ['result' => 'transferred'];
    }
} catch (App\Services\Defects\MemberCorrectionRefused $e) {
    $out = ['result' => 'refused', 'code' => $e->errorCode, 'status' => $e->status];
} catch (Illuminate\Validation\ValidationException $e) {
    $out = ['result' => 'refused', 'code' => 'VALIDATION', 'message' => json_encode($e->errors())];
}
echo json_encode($out);
PHP;

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
$pdo->query('SELECT pg_sleep(4)');
$pdo->commit();
PHP], base_path(), [
            'AUDIT_PG_DSN' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            'AUDIT_PG_DEFECT_ID' => (string) $defectId,
            'AUDIT_PG_PASSWORD' => (string) $connection['password'],
            'AUDIT_PG_USERNAME' => (string) $connection['username'],
        ]);
        $process->start();

        return $process;
    }

    private function waitUntilLocked(SymfonyProcess $holder): void
    {
        $deadline = microtime(true) + 5;
        $output = '';
        do {
            $output .= $holder->getIncrementalOutput();
            if (str_contains($output, 'LOCKED')) {
                return;
            }
            if (! $holder->isRunning()) {
                $this->fail('The lock holder exited before it locked the row: '.$holder->getErrorOutput());
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out waiting for the lock holder.');
    }
}
