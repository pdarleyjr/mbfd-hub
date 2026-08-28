<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Apparatus;
use App\Models\DailyCheckoutLedgerCutover;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ActivateDailyCheckoutLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const RELEASE_SHA = 'cccccccccccccccccccccccccccccccccccccccc';

    public function test_it_requires_an_immutable_release_sha(): void
    {
        $status = Artisan::call('daily-checkout:activate-ledger', ['--json' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('daily_checkout_ledger_cutover_release_sha_invalid', Artisan::output());
    }

    public function test_it_records_one_immutable_owner_beta_cutover_without_creating_status_transitions(): void
    {
        $first = $this->makeApparatus('E401', 'In Service');
        $second = $this->makeApparatus('R402', 'Out of Service');

        $status = Artisan::call('daily-checkout:activate-ledger', [
            '--release-sha' => self::RELEASE_SHA,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame('activated', $report['state']);
        $this->assertSame(self::RELEASE_SHA, $report['cutover']['release_sha']);
        $this->assertSame('owner_beta_activation', $report['cutover']['source']);
        $this->assertSame(2, $report['cutover']['apparatus_count']);
        $this->assertDatabaseCount('daily_checkout_ledger_cutovers', 1);
        $this->assertDatabaseCount('apparatus_operational_status_events', 0);

        $cutover = DailyCheckoutLedgerCutover::query()->sole();
        $this->assertSame('daily_checkout', $cutover->ledger);
        $this->assertSame(self::RELEASE_SHA, $cutover->release_sha);
        $this->assertSame('owner_beta_activation', $cutover->source);
        $this->assertSame([
            ['id' => $first->id, 'status' => 'In Service'],
            ['id' => $second->id, 'status' => 'Out of Service'],
        ], $cutover->apparatus_status_snapshot);
        $this->assertSame(hash('sha256', json_encode($cutover->apparatus_status_snapshot, JSON_THROW_ON_ERROR)), $cutover->snapshot_sha256);

        $this->expectException(LogicException::class);
        $cutover->update(['source' => 'rewritten']);
    }

    public function test_it_never_overwrites_an_existing_cutover_on_a_later_release(): void
    {
        $this->makeApparatus('E403', 'In Service');
        Artisan::call('daily-checkout:activate-ledger', [
            '--release-sha' => self::RELEASE_SHA,
            '--json' => true,
        ]);
        $original = DailyCheckoutLedgerCutover::query()->sole();

        $laterSha = str_repeat('d', 40);
        $status = Artisan::call('daily-checkout:activate-ledger', [
            '--release-sha' => $laterSha,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame('already_activated', $report['state']);
        $this->assertSame($laterSha, $report['requested_release_sha']);
        $this->assertSame(self::RELEASE_SHA, $report['cutover']['release_sha']);
        $this->assertSame(1, DailyCheckoutLedgerCutover::query()->count());
        $this->assertSame($original->activated_at->toIso8601String(), DailyCheckoutLedgerCutover::query()->sole()->activated_at->toIso8601String());
    }

    public function test_it_fails_closed_for_an_existing_incomplete_or_tampered_cutover_record(): void
    {
        $this->makeApparatus('E404', 'In Service');
        DB::table('daily_checkout_ledger_cutovers')->insert([
            'ledger' => DailyCheckoutLedgerCutover::LEDGER,
            'release_sha' => self::RELEASE_SHA,
            'source' => DailyCheckoutLedgerCutover::SOURCE,
            'activated_at' => now('UTC'),
            'apparatus_status_snapshot' => '[]',
            'snapshot_sha256' => str_repeat('0', 64),
            'apparatus_count' => 0,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $status = Artisan::call('daily-checkout:activate-ledger', [
            '--release-sha' => self::RELEASE_SHA,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame('blocked', $report['state']);
        $this->assertContains('daily_checkout_ledger_cutover_existing_invalid', $report['issues']);
        $this->assertDatabaseCount('daily_checkout_ledger_cutovers', 1);
    }

    public function test_database_rejects_query_builder_rewrites_and_deletion_of_the_cutover(): void
    {
        $this->makeApparatus('E405', 'In Service');
        Artisan::call('daily-checkout:activate-ledger', [
            '--release-sha' => self::RELEASE_SHA,
            '--json' => true,
        ]);
        $cutover = DailyCheckoutLedgerCutover::query()->sole();

        try {
            DB::transaction(function () use ($cutover): void {
                DB::table('daily_checkout_ledger_cutovers')
                    ->where('id', $cutover->id)
                    ->update(['activated_at' => now('UTC')->addHour()]);
            });
            $this->fail('Query Builder must not be able to rewrite Daily Checkout cutover evidence.');
        } catch (QueryException) {
            $this->assertSame($cutover->activated_at->toIso8601String(), DailyCheckoutLedgerCutover::query()->sole()->activated_at->toIso8601String());
        }

        try {
            DB::transaction(function () use ($cutover): void {
                DB::table('daily_checkout_ledger_cutovers')
                    ->where('id', $cutover->id)
                    ->delete();
            });
            $this->fail('Query Builder must not be able to delete Daily Checkout cutover evidence.');
        } catch (QueryException) {
            $this->assertDatabaseCount('daily_checkout_ledger_cutovers', 1);
        }
    }

    public function test_cutover_migration_explicitly_refuses_rollback_that_would_remove_activation_evidence(): void
    {
        $migration = require database_path('migrations/2026_08_27_000001_create_daily_checkout_ledger_cutovers_table.php');

        $this->expectException(LogicException::class);
        $migration->down();
    }

    private function makeApparatus(string $designation, string $status): Apparatus
    {
        $station = Station::query()->firstOrCreate([
            'station_number' => 40,
        ], [
            'name' => 'Station 40',
            'address' => '40 Test Street',
            'is_active' => true,
        ]);

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $designation,
            'designation' => $designation,
            'name' => $designation,
            'type' => 'Engine',
            'make' => 'Test',
            'model' => 'Test',
            'year' => 2026,
            'status' => $status,
            'daily_checkout_requirement' => 'required',
        ]);
    }
}
