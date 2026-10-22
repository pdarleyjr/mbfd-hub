<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Policies\ApparatusInspectionExceptionPolicy;
use App\Policies\ApparatusInspectionPolicy;
use App\Services\ApparatusInspectionApprovalService;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\Defects\FindingsResult;
use App\Services\Inspections\InspectionReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Inspections\Concerns\BuildsInspectionReviewFixtures;
use Tests\TestCase;

/** Reviewer decisions on held inspections (docs/daily-inspection-continuity.md sections 2.3 and 4.2). */
final class InspectionReviewServiceTest extends TestCase
{
    use BuildsInspectionReviewFixtures;
    use RefreshDatabase;

    private InspectionReviewService $review;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        $this->review = app(InspectionReviewService::class);
    }

    // Scenario F: each decision path for a mileage rollback.

    public function test_accepting_a_lower_reading_requires_an_explicit_meter_reset_confirmation(): void
    {
        [$inspection, $hold] = $this->heldRollback();
        $reviewer = $this->reviewer();

        try {
            $this->decideMeter($hold, $reviewer, ['action' => 'accept_submitted']);
            $this->fail('A lower reading must not be accepted silently.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_meter_reset', $exception->errors());
        }
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
        $this->assertSame('open', $hold->fresh()->status);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);

        $this->decideMeter($hold, $reviewer, ['action' => 'accept_submitted', 'confirm_meter_reset' => true, 'reason' => 'Odometer was replaced.']);

        $this->assertSame(900, $this->unit->fresh()->current_miles);
        $this->assertSame('resolved', $hold->fresh()->status);
        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $decision = ApparatusInspectionReviewEvent::query()->where('metadata->exception_id', $hold->id)->sole();
        $this->assertTrue($decision->metadata['meter_reset']);
        $this->assertSame(1000, $decision->metadata['prior_authoritative_meters']['miles']);
        $this->assertSame(900, $decision->metadata['result_authoritative_meters']['miles']);
        $release = ApparatusInspectionReviewEvent::query()->where('status', 'accepted')->latest('id')->first();
        $this->assertSame($reviewer->id, $release->metadata['released_by']);
        $this->assertSame($reviewer->id, $release->changed_by_user_id);
    }

    public function test_a_corrected_value_resolves_the_hold_and_releases_the_inspection(): void
    {
        [$inspection, $hold] = $this->heldRollback();

        $this->decideMeter($hold, $this->reviewer(), ['action' => 'correct', 'value' => 1025]);

        $this->assertSame(1025, $this->unit->fresh()->current_miles);
        $this->assertSame(900, $inspection->fresh()->miles, 'The submitted evidence is never rewritten.');
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
    }

    public function test_requesting_clarification_keeps_the_hold_open_and_tells_the_member(): void
    {
        [$inspection, $hold] = $this->heldRollback();

        $this->decideMeter($hold, $this->reviewer(), ['action' => 'request_revision', 'reason' => 'Please re-read the odometer.']);

        $this->assertSame('revision_requested', $hold->fresh()->status);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $message = ApparatusIssueMessage::sole();
        $this->assertSame($inspection->id, $message->apparatus_inspection_id);
        $this->assertSame('member', $message->visibility);
        $this->assertSame('Please re-read the odometer.', $message->body);
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
    }

    public function test_dismissing_keeps_the_authoritative_reading_and_releases_the_inspection(): void
    {
        [$inspection, $hold] = $this->heldRollback();

        $this->decideMeter($hold, $this->reviewer(), ['action' => 'dismiss', 'reason' => 'Mistyped reading.']);

        $this->assertSame('dismissed', $hold->fresh()->status);
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('status', 'accepted')->count());
    }

    public function test_a_member_revision_then_a_decision_releases_the_inspection(): void
    {
        [$inspection, $hold] = $this->heldRollback();
        $reviewer = $this->reviewer();
        $this->decideMeter($hold, $reviewer, ['action' => 'request_revision', 'reason' => 'Check the reading.']);
        app(ApparatusInspectionExceptionService::class)->submitRevision($hold->id, $this->member, ['reason' => 'Misread it.', 'value' => 1030]);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);

        $this->decideMeter($hold, $reviewer, ['action' => 'correct', 'value' => 1030]);

        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertSame(1030, $this->unit->fresh()->current_miles);
    }

    public function test_the_release_after_a_meter_decision_waits_for_every_other_hold(): void
    {
        $payload = $this->meterPayload(900, 401.2);
        $payload['review_flag'] = ['explanation' => 'Also look at the cab.'];
        $this->submit($payload)->assertCreated();
        $inspection = ApparatusInspection::sole();

        $this->decideMeter($this->holdFor($inspection, 'miles'), $this->reviewer(), ['action' => 'dismiss']);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status, 'The member flag is still open.');

        $this->review->approve($inspection->id, $this->reviewer());
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
    }

    // Scenario H and G: flag and significant damage holds.

    public function test_approving_a_flagged_inspection_resolves_the_flag_and_releases_it(): void
    {
        $inspection = $this->heldFlag();
        $reviewer = $this->reviewer();

        $approved = $this->review->approve($inspection->id, $reviewer, 'Looked at it; fine.');

        $this->assertSame('approved', $approved->review_status);
        $this->assertSame('accepted', $approved->processing_status);
        $this->assertSame('resolved', $this->holdFor($inspection, 'member_review_flag')->status);
        $event = ApparatusInspectionReviewEvent::query()->where('status', 'approved')->sole();
        $this->assertSame($reviewer->id, $event->changed_by_user_id);
        $this->assertSame('Looked at it; fine.', $event->internal_note);
        $this->assertSame(['member_review_flag'], $event->metadata['resolved_holds']);
        $this->assertSame('In Service', $this->unit->fresh()->status);
    }

    public function test_approving_significant_damage_does_not_take_the_unit_out_of_service(): void
    {
        $inspection = $this->heldSignificantDamage();

        $this->review->approve($inspection->id, $this->reviewer());

        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertSame('resolved', $this->holdFor($inspection, 'significant_damage')->status);
        $this->assertSame('In Service', $this->unit->fresh()->status);
        $this->assertDatabaseCount('apparatus_operational_status_events', 0);
        $this->assertFalse(ApparatusInspectionReviewEvent::query()->where('status', 'approved')->sole()->metadata['out_of_service_requested']);
    }

    public function test_out_of_service_is_an_explicit_separately_authorized_option(): void
    {
        $inspection = $this->heldSignificantDamage();
        // Reviewers work in the admin panel's web guard, where the permission is registered.
        auth()->shouldUse('web');

        try {
            $this->review->approve($inspection->id, $this->reviewer(mayUpdateApparatus: false), null, true);
            $this->fail('Approval authority alone must not confer apparatus update permission.');
        } catch (AuthorizationException) {
            $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
            $this->assertSame('In Service', $this->unit->fresh()->status);
        }

        $this->review->approve($inspection->id, $this->reviewer(mayUpdateApparatus: true), null, true);

        $this->assertSame('Out of Service', $this->unit->fresh()->status);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertDatabaseCount('apparatus_operational_status_events', 1);
    }

    public function test_approval_is_refused_while_a_meter_rollback_is_undecided(): void
    {
        $inspection = $this->heldSignificantDamage(miles: 900);
        $reviewer = $this->reviewer();

        try {
            $this->review->approve($inspection->id, $reviewer);
            $this->fail('The meter must be decided first.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Decide the meter reading first', $exception->errors()['inspection'][0]);
        }
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        $this->assertSame('open', $this->holdFor($inspection, 'significant_damage')->status);
        $this->assertSame(0, ApparatusInspectionReviewEvent::query()->where('status', 'approved')->count());

        $this->decideMeter($this->holdFor($inspection, 'miles'), $reviewer, ['action' => 'dismiss']);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status, 'Significant damage is still undecided.');

        $this->review->approve($inspection->id, $reviewer);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
    }

    public function test_approval_is_idempotent_and_leaves_one_terminal_event(): void
    {
        $inspection = $this->heldFlag();
        $reviewer = $this->reviewer();

        $this->review->approve($inspection->id, $reviewer);
        $this->review->approve($inspection->id, $this->reviewer());
        $rejected = $this->review->reject($inspection->id, $reviewer, 'Too late to reject.');

        $this->assertSame('approved', $rejected->review_status);
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('status', 'approved')->count());
        $this->assertSame(0, ApparatusInspectionReviewEvent::query()->where('status', 'rejected')->count());
    }

    public function test_the_legacy_approval_service_delegates_a_held_inspection(): void
    {
        $inspection = $this->heldFlag();

        $approved = app(ApparatusInspectionApprovalService::class)->approve($inspection->id, $this->reviewer());

        $this->assertSame('accepted', $approved->processing_status);
        $this->assertSame('resolved', $this->holdFor($inspection, 'member_review_flag')->status);
    }

    // Reject.

    public function test_rejecting_retains_the_evidence_dismisses_the_holds_and_applies_nothing_held(): void
    {
        $payload = $this->meterPayload(900, 401.2, withFinding: true);
        $payload['review_flag'] = ['explanation' => 'Something is off.'];
        $this->submit($payload)->assertCreated();
        $inspection = ApparatusInspection::sole();
        $reviewer = $this->reviewer();

        $rejected = $this->review->reject($inspection->id, $reviewer, 'The reading cannot be right.');

        $this->assertSame('rejected', $rejected->review_status);
        $this->assertSame('rejected', $rejected->processing_status);
        $this->assertSame('Rejected', $rejected->displayStatus());
        $this->assertSame(['dismissed', 'dismissed'], ApparatusInspectionException::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(1000, $this->unit->fresh()->current_miles, 'The held rollback reading is never applied.');
        $this->assertSame(900, $inspection->fresh()->miles);
        $this->assertSame(1, ApparatusDefect::query()->count(), 'Recorded defects remain.');
        $this->assertNotNull($inspection->fresh()->officer_signature);
        $event = ApparatusInspectionReviewEvent::query()->where('status', 'rejected')->sole();
        $this->assertSame('The reading cannot be right.', $event->internal_note);
        $this->assertCount(2, $event->metadata['dismissed_exception_ids']);
    }

    public function test_a_rejection_reason_is_required(): void
    {
        $inspection = $this->heldFlag();

        $this->expectException(ValidationException::class);
        $this->review->reject($inspection->id, $this->reviewer(), '   ');
    }

    // Legacy.

    public function test_a_legacy_pending_inspection_is_still_approved_and_rejected_through_its_own_path(): void
    {
        $approve = $this->legacyPending();
        $reject = $this->legacyPending();
        $reviewer = $this->reviewer();

        $approved = $this->review->approve($approve->id, $reviewer, 'Legacy note.');
        $rejected = $this->review->reject($reject->id, $reviewer, 'Legacy duplicate.');

        $this->assertSame('approved', $approved->review_status);
        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_inspection_id', $approve->id)->count());
        $this->assertSame('Legacy note.', ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $approve->id)->sole()->internal_note);
        $this->assertSame('rejected', $rejected->review_status);
        $this->assertNull($rejected->fresh()->processing_status);
    }

    // Clarification.

    public function test_a_clarification_request_is_recorded_and_visible_to_the_member_once(): void
    {
        $inspection = $this->heldFlag();
        $reviewer = $this->reviewer();
        $id = '6f1d6c1e-6f9f-4d6a-9c52-0d8c4a1c9b11';

        $this->review->requestClarification($inspection->id, $reviewer, 'Which seat belt?', $id);
        $this->review->requestClarification($inspection->id, $reviewer, 'Which seat belt?', $id);

        $message = ApparatusIssueMessage::sole();
        $this->assertSame('member', $message->visibility);
        $this->assertSame($reviewer->id, $message->author_user_id);
        $this->assertSame($inspection->id, $message->apparatus_inspection_id);
        $this->assertNull($message->apparatus_defect_id);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status, 'A question does not decide anything.');
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('status', 'clarification_requested')->count());
        $this->assertSame('open', $this->holdFor($inspection, 'member_review_flag')->status);
    }

    public function test_a_clarification_message_is_required(): void
    {
        $inspection = $this->heldFlag();

        $this->expectException(ValidationException::class);
        $this->review->requestClarification($inspection->id, $this->reviewer(), '  ');
    }

    // Authorization.

    public function test_an_ordinary_member_cannot_approve_reject_or_ask_for_clarification(): void
    {
        $inspection = $this->heldFlag();

        foreach ([
            fn () => $this->review->approve($inspection->id, $this->member),
            fn () => $this->review->reject($inspection->id, $this->member, 'No.'),
            fn () => $this->review->requestClarification($inspection->id, $this->member, 'Why?'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An ordinary member must not review inspections.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        $this->assertSame('open', $this->holdFor($inspection, 'member_review_flag')->status);
        $this->assertDatabaseCount('apparatus_issue_messages', 0);
    }

    public function test_a_fleet_manager_can_review_through_the_policies_and_the_service(): void
    {
        $inspection = $this->heldFlag();
        $manager = $this->fleetOperationsManager();
        $hold = $this->holdFor($inspection, 'member_review_flag');

        $this->assertTrue((new ApparatusInspectionPolicy)->approve($manager, $inspection));
        $this->assertTrue((new ApparatusInspectionPolicy)->reject($manager, $inspection));
        $this->assertTrue((new ApparatusInspectionExceptionPolicy)->viewAny($manager));
        $this->assertTrue((new ApparatusInspectionExceptionPolicy)->update($manager, $hold));
        $this->assertFalse((new ApparatusInspectionPolicy)->approve($this->member, $inspection));
        $this->assertFalse((new ApparatusInspectionExceptionPolicy)->update($this->member, $hold));

        $this->review->approve($inspection->id, $manager);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
    }

    public function test_a_fleet_manager_can_decide_a_meter_rollback(): void
    {
        [$inspection, $hold] = $this->heldRollback();

        $this->decideMeter($hold, $this->fleetOperationsManager(), ['action' => 'dismiss']);

        $this->assertSame('accepted', $inspection->fresh()->processing_status);
    }

    public function test_an_exception_from_another_inspection_cannot_be_decided_from_this_one(): void
    {
        [$inspection, $hold] = $this->heldRollback();
        $other = $this->heldFlag();

        try {
            app(ApparatusInspectionExceptionService::class)->reconcile($hold->id, $this->reviewer(), [
                'action' => 'dismiss', 'reason' => 'Forged.', 'expected_current_value' => 1000,
            ], $other->id);
            $this->fail('A forged exception id must not be decided.');
        } catch (ModelNotFoundException) {
            $this->assertSame('open', $hold->fresh()->status);
            $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        }
    }

    public function test_approving_one_inspection_never_touches_another_inspections_holds(): void
    {
        $first = $this->heldFlag();
        $second = $this->heldFlag();

        $this->review->approve($first->id, $this->reviewer());

        $this->assertSame('open', $this->holdFor($second, 'member_review_flag')->status);
        $this->assertSame('held_for_review', $second->fresh()->processing_status);
    }

    public function test_release_if_clear_does_nothing_while_a_hold_is_open(): void
    {
        $inspection = $this->heldFlag();

        DB::transaction(fn () => $this->assertFalse($this->review->releaseIfClear(ApparatusInspection::query()->lockForUpdate()->findOrFail($inspection->id), $this->reviewer())));

        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
    }

    /** @return array{ApparatusInspection, ApparatusInspectionException} */
    private function heldRollback(): array
    {
        $this->submit($this->meterPayload(900, 401.2))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
        $inspection = ApparatusInspection::query()->latest('id')->firstOrFail();

        return [$inspection, $this->holdFor($inspection, 'miles')];
    }

    private function heldFlag(): ApparatusInspection
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['review_flag'] = ['explanation' => 'The pump panel smells of smoke.', 'items' => $this->firstItems($payload, 1)];
        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        return ApparatusInspection::query()->latest('id')->firstOrFail();
    }

    /** A significant-damage hold built from an explicit findings result (the detection itself belongs to the findings service). */
    private function heldSignificantDamage(int $miles = 1020): ApparatusInspection
    {
        $this->submit($this->meterPayload($miles, 401.2, withFinding: true))->assertCreated();
        $inspection = ApparatusInspection::query()->latest('id')->firstOrFail();
        $service = app(ApparatusInspectionProcessingService::class);
        $service->recordNonMeterHolds($inspection, Apparatus::findOrFail($this->unit->id), new FindingsResult(
            significantNewOrEscalatedDefectIds: [ApparatusDefect::query()->firstOrFail()->id],
        ));
        $inspection->update(['review_status' => 'pending_review', 'processing_status' => 'held_for_review']);

        return $inspection->fresh();
    }

    private function legacyPending(): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $this->unit->id, 'operator_name' => 'Legacy', 'rank' => 'Firefighter', 'shift' => 'A',
            'review_status' => 'pending_review', 'engine_hours' => 402.0, 'miles' => 1030, 'completed_at' => now(),
            'pending_effects' => [
                'defects' => [['compartment' => 'Cab', 'item' => 'Radio', 'status' => 'Missing', 'notes' => 'Gone.']],
                'has_critical_defects' => true,
            ],
        ]);
    }
}
