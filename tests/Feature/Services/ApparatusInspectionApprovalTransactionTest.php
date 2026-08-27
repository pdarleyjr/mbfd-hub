<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ApparatusInspectionApprovalTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase intentionally leaves a transaction open around each
        // test. This focused acceptance suite must commit so it can verify
        // DB::afterCommit behavior, therefore it creates a fresh in-memory
        // schema without a surrounding test transaction.
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);

        config()->set('google_sheets.apparatus_sync_enabled', false);
        Http::preventStrayRequests();
    }

    public function test_repeated_approval_applies_each_local_effect_and_follow_up_job_once(): void
    {
        [$inspection, $reviewer] = $this->pendingInspection();
        Queue::fake();
        $this->primeDisplayCache();

        $service = app(ApparatusInspectionApprovalService::class);
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $service->approve($inspection->id, $reviewer);
            $service->approve($inspection->id, $reviewer);

            Queue::assertNothingPushed();
            $this->assertDisplayCacheWasPreserved();
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        $apparatus = $inspection->apparatus->fresh();

        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame('Out of Service', $apparatus->status);
        $this->assertSame('300.0', $apparatus->current_engine_hours);
        $this->assertSame(10_020, $apparatus->current_miles);
        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        $this->assertSame($inspection->id, ApparatusDefect::sole()->apparatus_inspection_id);
        Queue::assertPushed(PmAlertNotificationJob::class, 1);
        Queue::assertPushed(AuditEquipmentAfterInspection::class, 1);
        $this->assertDisplayCacheWasInvalidated();
    }

    public function test_an_approve_then_reject_collision_keeps_the_first_terminal_decision_and_one_set_of_effects(): void
    {
        [$inspection, $reviewer] = $this->pendingInspection();
        Queue::fake();

        $service = app(ApparatusInspectionApprovalService::class);
        $service->approve($inspection->id, $reviewer);
        $service->reject($inspection->id, $reviewer, 'A later request must not overwrite approval.');

        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        $this->assertSame('approved', ApparatusInspectionReviewEvent::sole()->status);
        Queue::assertPushed(PmAlertNotificationJob::class, 1);
        Queue::assertPushed(AuditEquipmentAfterInspection::class, 1);
    }

    public function test_repeated_rejection_keeps_one_terminal_decision_and_never_applies_operational_effects(): void
    {
        [$inspection, $reviewer] = $this->pendingInspection();
        Queue::fake();
        $this->primeDisplayCache();

        $service = app(ApparatusInspectionApprovalService::class);
        $service->reject($inspection->id, $reviewer, 'Evidence is duplicate.');
        $service->reject($inspection->id, $reviewer, 'A later request must not overwrite rejection.');

        $apparatus = $inspection->apparatus->fresh();

        $this->assertSame('rejected', $inspection->fresh()->review_status);
        $this->assertSame('In Service', $apparatus->status);
        $this->assertSame('249.0', $apparatus->current_engine_hours);
        $this->assertSame(10_000, $apparatus->current_miles);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        Queue::assertNotPushed(PmAlertNotificationJob::class);
        Queue::assertNotPushed(AuditEquipmentAfterInspection::class);
        $this->assertDisplayCacheWasInvalidated();
    }

    public function test_a_reject_then_approve_collision_keeps_the_first_terminal_decision_without_operational_effects(): void
    {
        [$inspection, $reviewer] = $this->pendingInspection();
        Queue::fake();

        $service = app(ApparatusInspectionApprovalService::class);
        $service->reject($inspection->id, $reviewer, 'The reviewer rejected the submitted evidence.');
        $service->approve($inspection->id, $reviewer);

        $apparatus = $inspection->apparatus->fresh();

        $this->assertSame('rejected', $inspection->fresh()->review_status);
        $this->assertSame('In Service', $apparatus->status);
        $this->assertSame('249.0', $apparatus->current_engine_hours);
        $this->assertSame(10_000, $apparatus->current_miles);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        Queue::assertNotPushed(PmAlertNotificationJob::class);
        Queue::assertNotPushed(AuditEquipmentAfterInspection::class);
    }

    public function test_an_outer_transaction_rollback_reverts_approval_and_suppresses_post_commit_side_effects(): void
    {
        [$inspection, $reviewer] = $this->pendingInspection();
        Queue::fake();
        $this->primeDisplayCache();

        try {
            DB::transaction(function () use ($inspection, $reviewer): void {
                app(ApparatusInspectionApprovalService::class)->approve($inspection->id, $reviewer);

                Queue::assertNothingPushed();
                $this->assertDisplayCacheWasPreserved();

                throw new RuntimeException('Simulated failure after approval writes.');
            });
            $this->fail('The simulated approval failure should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after approval writes.', $exception->getMessage());
        }

        $apparatus = $inspection->apparatus->fresh();

        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame('In Service', $apparatus->status);
        $this->assertSame('249.0', $apparatus->current_engine_hours);
        $this->assertSame(10_000, $apparatus->current_miles);
        $this->assertDatabaseCount('apparatus_defects', 0);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
        Queue::assertNotPushed(PmAlertNotificationJob::class);
        Queue::assertNotPushed(AuditEquipmentAfterInspection::class);
        $this->assertDisplayCacheWasPreserved();
    }

    /** @return array{ApparatusInspection, User} */
    private function pendingInspection(): array
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main Street',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => '1001',
            'designation' => 'E1',
            'slug' => 'engine-1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'current_engine_hours' => 249.0,
            'current_miles' => 10_000,
            'last_pm_engine_hours' => 0.0,
            'last_pm_mileage' => 0,
            'pm_interval_hours' => 300,
            'snipeit_asset_id' => 123,
        ]);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Daily Checkout Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => 'E1',
            'engine_hours' => 300.0,
            'miles' => 10_020,
            'review_status' => 'pending_review',
            'pending_effects' => [
                'defects' => [[
                    'compartment' => 'Cab',
                    'item' => 'Portable radio',
                    'status' => 'Missing',
                    'notes' => 'Not in assigned mount.',
                ]],
                'has_critical_defects' => true,
            ],
            'completed_at' => now(),
        ]);
        $role = Role::findOrCreate('admin', 'web');
        $reviewer = User::factory()->create();
        $reviewer->assignRole($role);

        return [$inspection, $reviewer];
    }

    private function primeDisplayCache(): void
    {
        Cache::put(DisplaySnapshotService::SNAPSHOT_CACHE_KEY, 'snapshot-before-decision');
        Cache::put(DisplaySnapshotService::STATIONS_CACHE_KEY, 'stations-before-decision');
    }

    private function assertDisplayCacheWasInvalidated(): void
    {
        $this->assertNull(Cache::get(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertNull(Cache::get(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }

    private function assertDisplayCacheWasPreserved(): void
    {
        $this->assertSame('snapshot-before-decision', Cache::get(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertSame('stations-before-decision', Cache::get(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }
}
