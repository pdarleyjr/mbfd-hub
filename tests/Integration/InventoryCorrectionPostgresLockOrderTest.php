<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInventoryCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

#[Group('postgres')]
final class InventoryCorrectionPostgresLockOrderTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;

    public function test_a_late_report_waits_for_the_inspection_before_taking_the_apparatus_foreign_key_lock(): void
    {
        $this->requireDisposablePostgres();
        $clientId = (string) Str::uuid();
        $draftId = (string) Str::uuid();
        $application = 'mbfd-inventory-lock-'.bin2hex(random_bytes(6));
        $inspection = null;
        $child = null;

        try {
            $this->setUpInventoryFleet();
            $inspection = ApparatusInspection::query()->create([
                'apparatus_id' => $this->units['E1']->id, 'actor_user_id' => $this->member->id,
                'inventory_draft_id' => $draftId, 'operator_name' => 'Inventory Reporter', 'rank' => 'Firefighter',
                'review_status' => 'approved', 'completed_at' => now(), 'results' => [],
            ]);
            $original = $inspection->refresh()->getRawOriginal();
            $item = $this->item('E1', 'Passports');
            $data = [
                'client_submission_id' => $clientId, 'inspection_draft_id' => $draftId,
                'checklist_version' => $this->resolved('E1')['checklist_version'],
                'reason' => 'no_longer_carried', 'compartment_id' => $item['compartment_id'], 'item_id' => $item['item_id'],
            ];
            $child = $this->reporterProcess($data, $application);

            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '3s'");
            ApparatusInspection::query()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            $child->start();
            $this->waitUntilChildIsBlocked($child, $application);

            // An INSERT first would retain apparatus KEY SHARE for its FK while
            // waiting on our inspection. FOR UPDATE here would then deadlock.
            $locked = Apparatus::query()->whereKey($this->units['E1']->id)->lockForUpdate()->firstOrFail();
            self::assertSame($this->units['E1']->id, $locked->id);
            self::assertSame(0, ApparatusInventoryCorrection::query()->where('client_request_id', $clientId)->count());
            DB::commit();

            $child->wait();
            self::assertTrue($child->isSuccessful(), $child->getErrorOutput());
            $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('completed', $result['status'] ?? null, json_encode($result, JSON_THROW_ON_ERROR));
            self::assertFalse($result['duplicate']);
            $correction = ApparatusInventoryCorrection::query()->where('client_request_id', $clientId)->sole();
            self::assertSame($inspection->id, $correction->apparatus_inspection_id);
            self::assertSame(ApparatusInventoryCorrection::STATUS_SUBMITTED, $correction->status);
            self::assertSame(1, $correction->events()->count());

            $replay = $this->service()->submit($this->member, $this->member->employee_profile_id, $this->unit('E1'), $data);
            self::assertTrue($replay['duplicate']);
            self::assertSame($correction->id, $replay['correction']->id);
            self::assertSame(1, $correction->events()->count());
            self::assertSame($original, $inspection->fresh()->getRawOriginal());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($child?->isRunning()) {
                $child->stop();
            }
            // Only fixtures from this explicitly disposable DB; no schema reset.
            $correctionIds = ApparatusInventoryCorrection::query()->where('client_request_id', $clientId)->pluck('id');
            foreach ($correctionIds as $id) {
                DB::table('inspection_notification_deliveries')->where('event_key', 'like', 'correction:'.$id.':%')->delete();
            }
            DB::table('apparatus_inventory_correction_events')->whereIn('apparatus_inventory_correction_id', $correctionIds)->delete();
            DB::table('apparatus_inventory_corrections')->whereIn('id', $correctionIds)->delete();
            if ($inspection !== null) {
                DB::table('apparatus_inspections')->where('id', $inspection->id)->delete();
            }
            DB::table('apparatuses')->whereIn('id', array_map(static fn (Apparatus $unit): int => $unit->id, $this->units))->delete();
            if (isset($this->member)) {
                DB::table('authentication_sessions')->where('user_id', $this->member->id)->delete();
                DB::table('users')->where('id', $this->member->id)->delete();
                DB::table('employees')->where('id', $this->member->employee_profile_id)->delete();
            }
        }
    }

    private function waitUntilChildIsBlocked(Process $child, string $application): void
    {
        $deadline = microtime(true) + 15;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $state = DB::selectOne('select wait_event_type, query from pg_stat_activity where application_name = ?', [$application]);
            if ($state?->wait_event_type === 'Lock') {
                self::assertStringContainsString('apparatus_inspections', (string) $state->query);

                return;
            }
            if (! $child->isRunning()) {
                self::fail('Reporter exited before the lock barrier: '.$child->getOutput().$child->getErrorOutput());
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('The reporter did not reach the inspection lock wait.');
    }

    /** @param array<string, mixed> $data */
    private function reporterProcess(array $data, string $application): Process
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || getenv('EXPECTED_TEST_DB_CONNECTION') !== 'pgsql'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('Only explicit disposable PostgreSQL is permitted.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Process::preventStrayProcesses();
Illuminate\Support\Facades\Notification::fake();
Illuminate\Support\Facades\Queue::fake();
config(['google_sheets.apparatus_sync_enabled' => false]);
Illuminate\Support\Facades\DB::select('select set_config(?, ?, false)', ['application_name', getenv('LOCK_APPLICATION')]);
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '10s'");
try {
    $reporter = App\Models\User::findOrFail((int) getenv('LOCK_REPORTER'));
    $apparatus = App\Models\Apparatus::findOrFail((int) getenv('LOCK_APPARATUS'));
    $result = app(App\Services\Inventory\InventoryCorrectionService::class)->submit(
        $reporter, $reporter->employee_profile_id, $apparatus,
        json_decode(getenv('LOCK_DATA'), true, flags: JSON_THROW_ON_ERROR),
    );
    echo json_encode(['status' => 'completed', 'duplicate' => $result['duplicate']], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'failed', 'exception_class' => $exception::class, 'code' => $exception->getCode()], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', $script], base_path(), [
            'APP_ENV' => 'testing', 'LOCK_REPORTER' => (string) $this->member->id,
            'LOCK_APPARATUS' => (string) $this->units['E1']->id, 'LOCK_APPLICATION' => $application,
            'LOCK_DATA' => json_encode($data, JSON_THROW_ON_ERROR),
            'SystemRoot' => (string) getenv('SystemRoot'), 'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV, timeout: 30);
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing') && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql' && DB::connection()->getDriverName() === 'pgsql'
            && DB::connection()->getConfig('host') === '127.0.0.1'
            && str_starts_with(DB::connection()->getDatabaseName(), 'mbfd_hub_test_')) {
            return;
        }
        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            self::fail('The inventory lock-order gate requires explicitly configured loopback disposable PostgreSQL.');
        }
        $this->markTestSkipped('Requires the disposable PostgreSQL two-connection gate.');
    }
}
