<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class BidAssignmentPostgresConcurrencyTest extends TestCase
{
    public function test_receiver_preserves_identity_lock_order_and_concurrent_correction_order(): void
    {
        if (getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1' || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the explicitly configured disposable PostgreSQL database.');
        }
        $suffix = bin2hex(random_bytes(6));
        $employee = Employee::query()->create(['employee_id' => 'BID-RACE-'.$suffix, 'name' => 'Synthetic Bid Race']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        $application = 'bid-race-'.$suffix;
        $children = [];
        try {
            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '3s'");
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $child = $this->writer($employee, 3, $application);
            $children[] = $child;
            $child->start();
            $deadline = microtime(true) + 15;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $state = DB::selectOne('select wait_event_type from pg_stat_activity where application_name = ?', [$application]);
                if ($state?->wait_event_type === 'Lock') {
                    break;
                }
                if (! $child->isRunning() || microtime(true) >= $deadline) {
                    self::fail('Receiver did not reach the expected User lock barrier: '.$child->getErrorOutput());
                }
                usleep(10_000);
            } while (true);
            // The child must wait for User before holding the Employee lock.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            DB::commit();
            $child->wait();
            self::assertTrue($child->isSuccessful(), $child->getErrorOutput());
            self::assertSame('accepted', trim($child->getOutput()));

            foreach ([4, 5] as $sequence) {
                $writer = $this->writer($employee, $sequence, $application.'-'.$sequence);
                $children[] = $writer;
                $writer->start();
            }
            foreach (array_slice($children, 1) as $writer) {
                $writer->wait();
                self::assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
                self::assertContains(trim($writer->getOutput()), ['accepted', 'rejected']);
            }
            $current = EmployeeBidAssignment::query()->where('employee_profile_id', $employee->id)->whereNull('superseded_at')->sole();
            self::assertSame(5, $current->source_sequence);
            self::assertGreaterThanOrEqual(2, $employee->bidAssignments()->count());
            self::assertSame(1, $employee->bidAssignments()->whereNull('superseded_at')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            // Only these random synthetic fixtures in the guarded disposable DB.
            DB::table('employee_profile_events')->where('employee_id', $employee->id)->delete();
            DB::table('employee_bid_assignments')->where('employee_profile_id', $employee->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
            DB::table('employees')->where('id', $employee->id)->delete();
        }
    }

    private function writer(Employee $employee, int $sequence, string $application): Process
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') { exit(2); }
Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[3]]);
$employee = $argv[1]; $sequence = (int) $argv[2];
$payload = ['payload_version'=>2,'bid_year'=>2026,'term_label'=>'2026–2027','bid_session_id'=>'TEST-RACE-2026',
    'employee_id'=>$employee,'rank_label'=>'Firefighter','shift_label'=>'A Shift','station_label'=>'Station #1',
    'division_label'=>'Operations','unit_label'=>'Combat Float','position_id'=>'A101','position_label'=>'Firefighter DE #2',
    'bid_selection_label'=>'Combat Float','assignment_type'=>'Floating','assignment_source'=>'bid_award','a_day_code'=>'G3',
    'a_day_label'=>'Group 3','picked_at'=>'2026-10-05T12:00:00Z','idempotency_key'=>$employee.'-'.$sequence,
    'is_forced'=>false,'admin_actor_employee_id'=>null,'source_sequence'=>$sequence,'source_result_hash'=>str_repeat('a',64),
    'source_workbook_sha256'=>str_repeat('b',64)];
try { app(App\Services\BidAssignmentReceiver::class)->receive($employee,$payload); echo 'accepted'; }
catch (Illuminate\Validation\ValidationException) { echo 'rejected'; }
PHP;

        return new Process([PHP_BINARY, '-d', 'extension=sodium', '-d', 'extension=pdo_pgsql', '-r', $script,
            $employee->employee_id, (string) $sequence, $application], base_path(), timeout: 30);
    }
}
