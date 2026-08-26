<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Uses two real PostgreSQL connections. The first connection holds the same
 * inspection row that the service locks; the second invokes the production
 * approval or rejection method with a short lock timeout. This exercises the
 * lock boundary rather than assuming SQLite's lockForUpdate support.
 *
 * It is deliberately guarded to an explicitly configured disposable test DB.
 */
#[Group('postgres')]
final class ApparatusInspectionApprovalPostgresLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('google_sheets.apparatus_sync_enabled', false);
        Http::preventStrayRequests();
    }

    public function test_postgresql_serializes_a_second_approval_and_a_conflicting_rejection(): void
    {
        if (! app()->environment('testing')
            || getenv('EXPECTED_TEST_DB_CONNECTION') !== 'pgsql'
            || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This regression requires the explicitly configured disposable PostgreSQL test database.');
        }

        [$station, $apparatus, $approvalInspection, $rejectionInspection, $reviewer] = $this->pendingInspections();
        Queue::fake();

        try {
            $service = app(ApparatusInspectionApprovalService::class);

            $this->assertDecisionWaitsForInspectionLock(
                $approvalInspection,
                fn (): ApparatusInspection => $service->approve($approvalInspection->id, $reviewer),
                'approval',
            );
            $this->assertDecisionWaitsForInspectionLock(
                $rejectionInspection,
                fn (): ApparatusInspection => $service->reject(
                    $rejectionInspection->id,
                    $reviewer,
                    'The concurrently submitted evidence was rejected.',
                ),
                'rejection',
            );

            Queue::assertNothingPushed();

            $service->approve($approvalInspection->id, $reviewer);
            $service->reject($rejectionInspection->id, $reviewer, 'The reviewer rejected the submitted evidence.');

            $this->assertSame('approved', $approvalInspection->fresh()->review_status);
            $this->assertSame('rejected', $rejectionInspection->fresh()->review_status);
            $this->assertSame('Out of Service', $apparatus->fresh()->status);
            $this->assertSame(2, DB::table('apparatus_inspection_review_events')
                ->whereIn('apparatus_inspection_id', [$approvalInspection->id, $rejectionInspection->id])
                ->count());
            $this->assertSame(1, DB::table('apparatus_defects')
                ->where('apparatus_inspection_id', $approvalInspection->id)
                ->count());
            Queue::assertPushed(PmAlertNotificationJob::class, 1);
            Queue::assertPushed(AuditEquipmentAfterInspection::class, 1);
        } finally {
            $this->deleteFixture($station, $apparatus, $approvalInspection, $rejectionInspection, $reviewer);
        }
    }

    /** @param callable(): ApparatusInspection $decision */
    private function assertDecisionWaitsForInspectionLock(
        ApparatusInspection $inspection,
        callable $decision,
        string $label,
    ): void {
        $primaryConnectionName = DB::getDefaultConnection();
        $secondaryConnectionName = 'apparatus_inspection_decision_lock_'.str_replace('.', '_', uniqid('', true));
        config([
            "database.connections.{$secondaryConnectionName}" => config("database.connections.{$primaryConnectionName}"),
        ]);
        DB::purge($secondaryConnectionName);

        $primaryConnection = DB::connection($primaryConnectionName);
        $secondaryConnection = DB::connection($secondaryConnectionName);
        $primaryConnection->beginTransaction();

        try {
            $this->assertNotNull(
                $primaryConnection->table('apparatus_inspections')
                    ->where('id', $inspection->id)
                    ->lockForUpdate()
                    ->first(),
            );
            $secondaryConnection->statement("SET lock_timeout = '250ms'");
            DB::setDefaultConnection($secondaryConnectionName);

            try {
                $decision();
                $this->fail("The concurrent {$label} should have waited for the inspection row lock.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
            } finally {
                DB::setDefaultConnection($primaryConnectionName);
            }
        } finally {
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }

            DB::disconnect($secondaryConnectionName);
            DB::purge($secondaryConnectionName);
        }
    }

    /** @return array{Station, Apparatus, ApparatusInspection, ApparatusInspection, User} */
    private function pendingInspections(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));
        $station = Station::query()->create([
            'station_number' => random_int(900_000, 999_999),
            'name' => "PostgreSQL Review Race {$suffix}",
            'address' => '901 Test Way',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => "E-RACE-{$suffix}",
            'name' => 'Engine Review Race',
            'type' => 'Engine',
            'vehicle_number' => "RACE-{$suffix}",
            'designation' => "E{$suffix}",
            'slug' => "engine-review-race-{$suffix}",
            'make' => 'Test',
            'model' => 'Lock',
            'year' => 2026,
            'status' => 'In Service',
            'current_engine_hours' => 249.0,
            'current_miles' => 10_000,
            'last_pm_engine_hours' => 0.0,
            'last_pm_mileage' => 0,
            'pm_interval_hours' => 300,
            'snipeit_asset_id' => 123,
        ]);
        $approvalInspection = $this->pendingInspection($apparatus, "{$suffix}-approve");
        $rejectionInspection = $this->pendingInspection($apparatus, "{$suffix}-reject");
        $reviewer = User::factory()->create();

        return [$station, $apparatus, $approvalInspection, $rejectionInspection, $reviewer];
    }

    private function pendingInspection(Apparatus $apparatus, string $reference): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'PostgreSQL Review Race Tester',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $apparatus->designation,
            'inspection_reference' => "INS-{$reference}",
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
    }

    private function deleteFixture(
        Station $station,
        Apparatus $apparatus,
        ApparatusInspection $approvalInspection,
        ApparatusInspection $rejectionInspection,
        User $reviewer,
    ): void {
        $inspectionIds = [$approvalInspection->id, $rejectionInspection->id];

        DB::table('apparatus_inspection_review_events')
            ->whereIn('apparatus_inspection_id', $inspectionIds)
            ->delete();
        DB::table('apparatus_defects')
            ->whereIn('apparatus_inspection_id', $inspectionIds)
            ->delete();
        DB::table('apparatus_inspections')->whereIn('id', $inspectionIds)->delete();
        DB::table('apparatuses')->where('id', $apparatus->id)->delete();
        DB::table('stations')->where('id', $station->id)->delete();
        DB::table('users')->where('id', $reviewer->id)->delete();
    }
}
