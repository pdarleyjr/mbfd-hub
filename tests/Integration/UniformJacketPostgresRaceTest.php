<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Employee;
use App\Models\PersonnelRequest;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class UniformJacketPostgresRaceTest extends TestCase
{
    #[DataProvider('submissionKeys')]
    public function test_concurrent_orders_share_one_jacket_allowance_and_preserve_retries(bool $sameKey): void
    {
        $this->requireDisposablePostgres();
        $suffix = bin2hex(random_bytes(6));
        $employee = Employee::query()->create(['employee_id' => 'JACKET-RACE-'.$suffix, 'name' => 'Synthetic Jacket Race',
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
        $applications = ['jacket-race-'.$suffix.'-1', 'jacket-race-'.$suffix.'-2'];
        $keys = ['jacket-race-'.$suffix.'-1', 'jacket-race-'.$suffix.($sameKey ? '-1' : '-2')];
        $children = [
            $this->writer($employee, $keys[0], $applications[0], 'vintage'),
            $this->writer($employee, $keys[1], $applications[1], 'softshell'),
        ];

        try {
            DB::beginTransaction();
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            foreach ($children as $child) {
                $child->start();
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('application_name', $applications)
                    ->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                if (microtime(true) >= $deadline || ! $children[0]->isRunning() || ! $children[1]->isRunning()) {
                    $this->fail('Both jacket writers did not reach the beneficiary lock: '.implode(' ', array_map(fn (Process $child): string => $child->getErrorOutput(), $children)));
                }
                usleep(10_000);
            } while (true);
            DB::commit();

            $results = [];
            foreach ($children as $child) {
                $child->wait();
                $this->assertTrue($child->isSuccessful(), $child->getErrorOutput());
                $results[] = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $requests = PersonnelRequest::query()->where('beneficiary_employee_id', $employee->id)->get();
            $this->assertCount(1, $requests);
            $this->assertSame(1, $requests->sole()->items()->where('item_code', 'jacket')->sum('quantity'));
            $this->assertSame(1, $requests->sole()->updates()->where('event', 'submitted')->count());
            if ($sameKey) {
                $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
                $this->assertSame($results[0]['id'], $results[1]['id']);
            } else {
                $this->assertEqualsCanonicalizing(['accepted', 'blocked'], array_column($results, 'status'));
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            DB::table('personnel_requests')->where('beneficiary_employee_id', $employee->id)->delete();
            DB::table('employee_profile_events')->where('employee_id', $employee->id)->delete();
            DB::table('employees')->where('id', $employee->id)->delete();
        }
    }

    public static function submissionKeys(): array
    {
        return ['same idempotency key' => [true], 'different styles and keys' => [false]];
    }

    private function writer(Employee $employee, string $key, string $application, string $style): Process
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') { exit(2); }
Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[3]]);
$employee = App\Models\Employee::query()->findOrFail((int) $argv[1]);
try {
    $request = app(App\Services\PersonnelRequests\PersonnelRequestSubmissionService::class)->submitUniform($employee,
        [['item_code'=>'jacket','quantity'=>1,'metadata'=>['jacket_style'=>$argv[4],'size'=>'L']]], $argv[2]);
    echo json_encode(['status'=>'accepted','id'=>$request->id], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $exception) {
    if (! array_key_exists('items.jacket.quantity', $exception->errors())) { throw $exception; }
    echo json_encode(['status'=>'blocked'], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, '-d', 'extension=sodium', '-d', 'extension=pdo_pgsql', '-r', $script,
            (string) $employee->id, $key, $application, $style], base_path(), timeout: 30);
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing') && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql' && DB::connection()->getDriverName() === 'pgsql') {
            return;
        }
        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            $this->fail('Jacket races require the explicitly configured disposable PostgreSQL test database.');
        }
        $this->markTestSkipped('Requires the explicitly configured disposable PostgreSQL test database.');
    }
}
