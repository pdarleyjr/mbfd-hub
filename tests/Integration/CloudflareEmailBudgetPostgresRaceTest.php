<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * Two independent PHP workers contend for the final monthly recipient unit.
 * The parent holds the budget row until PostgreSQL reports both workers waiting
 * on a lock, so a sequential replay cannot accidentally satisfy this test.
 * No dispatcher or provider HTTP endpoint is invoked.
 */
#[Group('postgres')]
final class CloudflareEmailBudgetPostgresRaceTest extends TestCase
{
    public function test_only_one_concurrent_reservation_can_consume_the_last_monthly_unit(): void
    {
        $this->requireDisposablePostgres();
        $at = CarbonImmutable::parse('2190-01-04T12:00:00Z');
        $account = str_repeat('a', 32);
        $budget = CloudflareUsageBudget::query()->create([
            'cycle_start' => $at->startOfDay(), 'cycle_end' => $at->addMonth()->startOfDay(),
            'provider_account_id' => $account, 'provider_chargeable_used' => 2849,
            'provider_daily_quota' => 1000, 'provider_daily_used' => 0, 'hub_safe_ceiling' => 2850,
            'reconciled_at' => $at, 'provider_daily_reconciled_at' => $at,
        ]);
        $emails = collect([0, 1])->map(fn (int $index): OutboundEmail => OutboundEmail::query()->create([
            'provider' => 'cloudflare', 'source_type' => 'postgres_budget_race',
            'from_address' => 'info@mbfdhub.test', 'to_recipients' => ['race-'.$index.'@example.test'],
            'subject' => 'Isolated PostgreSQL reservation race', 'recipient_count' => 1,
            'chargeable_budget_units' => 1, 'status' => 'pending',
        ]));
        $emailIds = $emails->pluck('id')->all();
        $barrier = tempnam(sys_get_temp_dir(), 'mbfd-email-race-');
        if ($barrier === false) {
            $this->fail('Unable to allocate the reservation race barrier.');
        }
        unlink($barrier);
        $suffix = bin2hex(random_bytes(8));
        $names = ['mbfd-email-race-'.$suffix.'-0', 'mbfd-email-race-'.$suffix.'-1'];
        $workers = [
            $this->reservationProcess($emails[0]->id, $account, $at, $barrier, $names[0]),
            $this->reservationProcess($emails[1]->id, $account, $at, $barrier, $names[1]),
        ];

        try {
            DB::beginTransaction();
            CloudflareUsageBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            foreach ($workers as $worker) {
                $worker->start();
            }
            $this->waitForWorkers($workers, fn (): bool => is_file($barrier.'.'.$names[0]) && is_file($barrier.'.'.$names[1]));
            touch($barrier);
            $this->waitForWorkers($workers, function () use ($names): bool {
                // Statistics can otherwise remain cached for this transaction.
                DB::select('SELECT pg_stat_clear_snapshot()');

                return DB::table('pg_stat_activity')->whereIn('application_name', $names)
                    ->where('wait_event_type', 'Lock')->count() === 2;
            });
            DB::commit();

            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertSame(0, $result['provider_requests']);
                $statuses[] = $result['status'];
            }
            sort($statuses);
            self::assertSame(['blocked', 'reserved'], $statuses);
            self::assertSame(1, OutboundEmail::query()->whereKey($emailIds)->where('status', 'reserved')->count());
            self::assertSame(1, OutboundEmail::query()->whereKey($emailIds)->where('status', 'pending')->count());
            self::assertSame(1, (int) OutboundEmail::query()->whereKey($emailIds)
                ->whereNotNull('budget_reserved_at')->whereNull('budget_released_at')->sum('chargeable_budget_units'));
            self::assertSame(0, OutboundEmail::query()->whereKey($emailIds)->whereNotNull('submitted_at')->count());
            Http::assertNothingSent();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$barrier, $barrier.'.'.$names[0], $barrier.'.'.$names[1]] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            OutboundEmail::query()->whereKey($emailIds)->delete();
            $budget->delete();
        }
    }

    /** @param list<SymfonyProcess> $workers */
    private function waitForWorkers(array $workers, callable $condition): void
    {
        $deadline = microtime(true) + 10;
        while (! $condition()) {
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning(), 'Race worker exited before the shared lock barrier: '.$worker->getErrorOutput().$worker->getOutput());
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for both reservation workers at the PostgreSQL lock barrier.');
            }
            usleep(10_000);
        }
    }

    private function reservationProcess(int $emailId, string $account, CarbonImmutable $at, string $barrier, string $name): SymfonyProcess
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
config(['communications.cloudflare.account_id' => getenv('RACE_ACCOUNT'), 'communications.cloudflare.api_token' => '',
    'communications.cloudflare.safe_email_ceiling' => 2850, 'communications.cloudflare.max_recipient_units_per_minute' => 5]);
Illuminate\Support\Facades\DB::select('SELECT set_config(?, ?, false)', ['application_name', getenv('RACE_NAME')]);
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '15s'");
touch(getenv('RACE_BARRIER').'.'.getenv('RACE_NAME'));
$deadline = microtime(true) + 10;
while (! is_file(getenv('RACE_BARRIER'))) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for reservation race barrier.');
    }
    usleep(10_000);
}
try {
    $email = App\Models\OutboundEmail::query()->findOrFail((int) getenv('RACE_EMAIL_ID'));
    app(App\Services\Communications\CloudflareCostGuard::class)->reserve($email, Carbon\CarbonImmutable::parse(getenv('RACE_AT')));
    $status = 'reserved';
} catch (App\Exceptions\EmailBudgetExhausted $exception) {
    $status = 'blocked';
}
Illuminate\Support\Facades\Http::assertNothingSent();
echo json_encode(['status' => $status, 'provider_requests' => Illuminate\Support\Facades\Http::recorded()->count()], JSON_THROW_ON_ERROR);
PHP;

        return new SymfonyProcess([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-d', 'display_errors=stderr', '-r', $script], base_path(), [
            'APP_ENV' => 'testing', 'RACE_BARRIER' => $barrier, 'RACE_EMAIL_ID' => (string) $emailId,
            'RACE_ACCOUNT' => $account, 'RACE_AT' => $at->toIso8601String(), 'RACE_NAME' => $name,
            'SystemRoot' => (string) getenv('SystemRoot'), 'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV, timeout: 25);
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
