<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Services\Fleet\VehicleSwapDecisionException;
use App\Services\Fleet\VehicleSwapService;
use App\Services\Inspections\InspectionReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Inspections\Concerns\BuildsInspectionReviewFixtures;
use Tests\TestCase;

/** A vehicle-swap hold combined with the meter and member-flag holds. */
final class InspectionSwapHoldTest extends TestCase
{
    use BuildsInspectionReviewFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
    }

    public function test_the_review_service_never_decides_a_swap_held_inspection(): void
    {
        $this->heldRequest();
        $inspection = ApparatusInspection::sole();
        $reviewer = $this->reviewer();

        foreach ([
            fn () => app(InspectionReviewService::class)->approve($inspection->id, $reviewer),
            fn () => app(InspectionReviewService::class)->reject($inspection->id, $reviewer, 'Not now.'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A swap-held checkout is decided only from Pending vehicle swaps.');
            } catch (VehicleSwapDecisionException $exception) {
                $this->assertStringContainsString('vehicle swap', $exception->getMessage());
            }
        }
        $this->assertSame('pending_vehicle_swap', $inspection->fresh()->processing_status);
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
    }

    public function test_a_swap_decision_then_meter_and_flag_holds_keep_the_inspection_pending_until_all_are_clear(): void
    {
        $payload = $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed', 'note' => 'Engine 3 was in the shop.'], true);
        $payload['review_flag'] = ['explanation' => 'Please check the new truck.', 'items' => $this->firstItems($payload, 1)];
        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'pending_vehicle_swap');
        $inspection = ApparatusInspection::sole();
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame('Please check the new truck.', $inspection->review_flag_explanation, 'The flag is saved with the held checkout.');

        $swap = $inspection->vehicleSwapRequest;
        $manager = $this->fleetOperationsManager();
        app(VehicleSwapService::class)->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);

        // Judged against the confirmed truck (900 h / 5000 mi) the reported readings are rollbacks, and the flag is open.
        $inspection = $inspection->fresh();
        $this->assertSame('pending_review', $inspection->review_status);
        $this->assertSame('held_for_review', $inspection->processing_status);
        $this->assertSame($this->frontline->id, $inspection->processed_apparatus_id);
        $this->assertSame(3, ApparatusInspectionException::query()->where('blocks_acceptance', true)->count());
        $this->assertSame([$this->frontline->id], ApparatusInspectionException::query()->pluck('apparatus_id')->unique()->values()->all());
        $this->assertSame('900.0', $this->frontline->fresh()->current_engine_hours);

        $review = app(InspectionReviewService::class);
        try {
            $review->approve($inspection->id, $manager);
            $this->fail('The meters must be decided first.');
        } catch (ValidationException) {
            $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        }

        // The meter decisions are made against the confirmed truck's own readings.
        $this->decideMeter($this->holdFor($inspection, 'miles'), $manager, ['action' => 'dismiss']);
        $this->decideMeter($this->holdFor($inspection, 'engine_hours'), $manager, ['action' => 'dismiss']);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status, 'The member flag is still open.');
        $this->assertSame(5000, $this->frontline->fresh()->current_miles);

        $review->approve($inspection->id, $manager);

        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
    }

    public function test_a_rejected_swap_still_applies_the_held_flag_to_the_assigned_truck(): void
    {
        $payload = $this->payload(['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed', 'note' => 'Not sure.']);
        $payload['review_flag'] = ['explanation' => 'Check the cab.'];
        $this->submit($payload)->assertCreated();
        $inspection = ApparatusInspection::sole();

        app(VehicleSwapService::class)->decide($inspection->vehicleSwapRequest->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_REJECT);

        $inspection = $inspection->fresh();
        $this->assertSame('held_for_review', $inspection->processing_status);
        $this->assertSame($this->unit->id, $inspection->processed_apparatus_id);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours, 'The reading is fine for the assigned truck.');
        $this->assertSame(['member_review_flag'], ApparatusInspectionException::query()->pluck('field')->all());
    }
}
