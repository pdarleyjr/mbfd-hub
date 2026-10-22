<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Filament\Employee\Pages\MyVehicleInspectionView;
use App\Filament\Resources\ApparatusInspectionExceptionResource\Pages\ListInspectionExceptions;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Filament\Resources\InspectionResource\Pages\ListInspections;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\ApparatusInspectionApprovalService;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Fleet\VehicleSwapService;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Services\Inspections\InspectionReviewService;
use App\Services\Inspections\IssueMessageService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * Independent end-to-end verification (Agent F) of the daily inspection
 * continuity directive: every scenario enters through the real
 * POST /api/public/apparatuses/{id}/inspections endpoint and is then driven
 * through the real services. Nothing here trusts another agent's test.
 */
final class DailyInspectionAcceptanceScenariosTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use RefreshDatabase;

    private User $alpha;

    private User $beta;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        $this->buildWorld();
        $this->alpha = $this->newMember('Alpha Member');
        $this->beta = $this->newMember('Beta Member');
        $this->reviewer = $this->newReviewer('Logistics Reviewer');
    }

    // ---------------------------------------------------------------- scenario A

    public function test_a_routine_inspection_is_auto_accepted_and_lands_in_the_vehicle_history(): void
    {
        $this->actAs($this->alpha);
        $response = $this->submit($this->payload())->assertCreated()
            ->assertJsonPath('review_status', 'approved')->assertJsonPath('processing_status', 'accepted');
        $inspection = ApparatusInspection::query()->findOrFail($response->json('id'));

        $this->assertSame($this->alpha->id, $inspection->actor_user_id);
        $this->assertSame($this->unit->id, $inspection->processed_apparatus_id);
        $this->assertSame('Accepted', $inspection->displayStatus());
        $this->assertSame(0, ApparatusDefect::query()->count());
        $this->assertSame(0, ApparatusInspectionException::query()->count());
        $this->assertSame(1020, $this->unit->fresh()->current_miles);

        $workspace = app(InspectionWorkspace::class)->build($inspection, $this->reviewer);
        $row = collect($workspace['history'])->firstWhere('id', $inspection->id);
        $this->assertNotNull($row, 'The accepted inspection must be in the physical vehicle history.');
        $this->assertSame('Accepted', $row['status']['label']);
        $this->assertSame(0, $workspace['header']['open_holds']);
        $this->assertTrue(app(EmployeeInspectionHistoryService::class)->ownedQuery($this->alpha)->whereKey($inspection->id)->exists());

        $html = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
        $this->assertStringContainsString('Accepted', $html);

        // Routine acceptance is silent: nobody is notified, nothing needs a decision.
        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- scenario B

    public function test_one_missing_battery_is_accepted_opens_one_issue_and_logistics_can_acknowledge_with_a_member_message(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery not in the radio charger.']);
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'accepted')->json('id'),
        );

        $defect = ApparatusDefect::query()->sole();
        $this->assertFalse($defect->resolved);
        $this->assertSame('reported', $defect->action_status);
        $this->assertSame($inspection->id, $defect->apparatus_inspection_id);
        $this->assertSame($inspection->id, $defect->first_reported_inspection_id);
        $this->assertSame($this->alpha->id, $defect->first_reported_by_user_id);
        $this->assertSame('missing', $defect->issue_type);
        $this->assertSame($this->itemRef($payload)['item_id'], $defect->item_key);
        $this->assertSame('reported', ApparatusDefectParticipant::query()->where('user_id', $this->alpha->id)->sole()->enrollment_source);
        $this->assertSame('In Service', $this->unit->fresh()->status);
        $this->assertSame(0, ApparatusInspectionException::query()->count());
        // A new routine issue is not an alarm: reviewers are not pushed a notification for it.
        Notification::assertNothingSent();

        app(DefectCorrectiveActionService::class)->acknowledge($defect->id, $this->reviewer, [
            'member_message' => 'Logistics has your report and is checking stock.',
        ]);

        $defect->refresh();
        $this->assertSame('acknowledged', $defect->action_status);
        $this->assertSame($this->reviewer->id, $defect->acknowledged_by_user_id);
        $event = ApparatusDefectEvent::query()->where('event_type', 'acknowledged')->sole();
        $this->assertSame('Logistics has your report and is checking stock.', $event->member_message);
        $message = ApparatusIssueMessage::query()->where('apparatus_defect_id', $defect->id)->sole();
        $this->assertSame('member', $message->visibility);
        $this->assertSame(1, $this->sentCount($this->alpha, 'issue_member_update'));
        $this->assertSame(0, $this->sentCount($this->reviewer, 'issue_member_update'));

        $html = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
        $this->assertStringContainsString('Logistics has your report and is checking stock.', $html);
        $this->assertStringContainsString('Acknowledged by Logistics', $html);
    }

    // ---------------------------------------------------------------- scenario C

    public function test_a_second_member_next_day_sees_the_finding_and_confirming_it_adds_an_observation_not_a_second_defect(): void
    {
        $alphaPayload = $this->payload();
        $this->markItem($alphaPayload, 0, 0, 'Missing', ['notes' => 'Battery not in the radio charger.']);
        $this->actAs($this->alpha);
        $first = ApparatusInspection::query()->findOrFail($this->submit($alphaPayload)->assertCreated()->json('id'));

        $this->travel(1)->days();
        $this->actAs($this->beta);
        $ref = $this->itemRef($alphaPayload);

        $findings = $this->getJson($this->checklistUrl())->assertOk()->json('open_findings');
        $this->assertCount(1, $findings);
        $this->assertSame($ref['compartment_id'], $findings[0]['compartment_id']);
        $this->assertSame($ref['item_id'], $findings[0]['item_id']);
        $this->assertSame('reported', $findings[0]['action_status']);
        $this->assertSame('Awaiting verification', $findings[0]['action_label']);
        $this->assertSame('missing', $findings[0]['issue_type']);
        $this->assertNotNull($findings[0]['first_reported_at']);
        $this->assertArrayNotHasKey('internal_note', $findings[0]);

        $betaPayload = $this->payload(null, 1050, 405.0);
        $this->markItem($betaPayload, 0, 0, 'Missing', ['notes' => 'Still missing this morning.']);
        $second = ApparatusInspection::query()->findOrFail(
            $this->submit($betaPayload)->assertCreated()->assertJsonPath('processing_status', 'accepted')->json('id'),
        );

        $defect = ApparatusDefect::query()->sole();
        $this->assertSame($first->id, $defect->apparatus_inspection_id, 'The origin inspection is never overwritten.');
        $this->assertSame($first->id, $defect->first_reported_inspection_id);
        $this->assertSame($this->alpha->id, $defect->first_reported_by_user_id);
        $this->assertSame($second->id, $defect->last_observed_inspection_id);
        $this->assertSame('Battery not in the radio charger.', $defect->notes, 'Confirming must not overwrite the origin notes.');
        $this->assertSame('reported', $defect->action_status);

        $observations = ApparatusDefectObservation::query()->where('apparatus_defect_id', $defect->id)->orderBy('id')->get();
        $this->assertSame(['first_reported', 'confirmed_again'], $observations->pluck('observation')->all());
        $this->assertSame([$this->alpha->id, $this->beta->id], $observations->pluck('actor_user_id')->all());
        $this->assertSame([$first->id, $second->id], $observations->pluck('apparatus_inspection_id')->all());

        $participants = ApparatusDefectParticipant::query()->where('apparatus_defect_id', $defect->id)->pluck('enrollment_source', 'user_id')->all();
        $this->assertSame([$this->alpha->id => 'reported', $this->beta->id => 'confirmed'], $participants);
        $this->assertSame(1050, $this->unit->fresh()->current_miles);
        $this->assertSame(0, ApparatusInspectionException::query()->count());
        // A known unresolved issue never repeat-alerts anyone.
        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- scenario D

    public function test_replacement_available_with_instructions_reaches_both_participants_and_the_issue_stays_open(): void
    {
        ['defect' => $defect, 'first' => $first] = $this->reportedByAlphaConfirmedByBeta();

        app(DefectCorrectiveActionService::class)->updateAction($defect->id, $this->reviewer, [
            'action_status' => 'replacement_available',
            'details' => ['pickup_instructions' => 'Pick up a charged battery at Logistics, 8 to 4.', 'assigned_department' => 'Logistics'],
            'member_message' => 'A replacement battery is ready. Pick it up at Logistics between 8 and 4.',
            'internal_note' => 'INTERNAL-ONLY: spare came from the E7 reserve stock.',
        ]);

        $defect->refresh();
        $this->assertSame('replacement_available', $defect->action_status);
        $this->assertFalse($defect->resolved);
        $this->assertSame('Pick up a charged battery at Logistics, 8 to 4.', $defect->action_details['pickup_instructions']);
        $event = ApparatusDefectEvent::query()->where('event_type', 'replacement_available')->sole();
        $this->assertSame('A replacement battery is ready. Pick it up at Logistics between 8 and 4.', $event->member_message);
        $this->assertSame('member', ApparatusIssueMessage::query()->where('apparatus_defect_id', $defect->id)->sole()->visibility);

        $this->assertSame(1, $this->sentCount($this->alpha, 'issue_member_update'));
        $this->assertSame(1, $this->sentCount($this->beta, 'issue_member_update'));
        $this->assertSame(0, $this->sentCount($this->reviewer, 'issue_member_update'));

        // The next member's checkout shows the new state and the latest member-visible update.
        $this->actAs($this->alpha);
        $findings = $this->getJson($this->checklistUrl())->assertOk()->json('open_findings');
        $this->assertCount(1, $findings);
        $this->assertSame('Replacement available', $findings[0]['action_label']);
        $this->assertSame('A replacement battery is ready. Pick it up at Logistics between 8 and 4.', $findings[0]['latest_member_update']['text']);
        $this->assertStringNotContainsString('INTERNAL-ONLY', json_encode($findings, JSON_THROW_ON_ERROR));

        $html = $this->get('/employee/my-vehicle-inspections/'.$first->id)->assertOk()->getContent();
        $this->assertStringContainsString('Replacement available', $html);
        $this->assertStringContainsString('A replacement battery is ready.', $html);
        $this->assertStringNotContainsString('INTERNAL-ONLY', $html);
    }

    // ---------------------------------------------------------------- scenario E

    public function test_resolving_notifies_each_participant_exactly_once_and_clears_the_next_checklist_without_rewriting_history(): void
    {
        ['defect' => $defect, 'first' => $first, 'second' => $second] = $this->reportedByAlphaConfirmedByBeta();
        $beforeResults = [$first->fresh()->results, $second->fresh()->results];
        $beforeObservations = ApparatusDefectObservation::query()->orderBy('id')->get(['id', 'observation', 'reported_status', 'notes'])->toArray();

        $service = app(DefectCorrectiveActionService::class);
        $service->resolve($defect->id, $this->reviewer, [
            'resolution_type' => 'replacement_installed',
            'resolution_notes' => 'Replacement battery installed in the radio.',
        ], 'resolve-key-1');
        // A retried click (same key) and a second administrator resolving later are both no-ops.
        $service->resolve($defect->id, $this->reviewer, [
            'resolution_type' => 'replacement_installed',
            'resolution_notes' => 'Replacement battery installed in the radio.',
        ], 'resolve-key-1');
        $secondAdmin = $this->newReviewer('Second Reviewer');
        $service->resolve($defect->id, $secondAdmin, [
            'resolution_type' => 'other',
            'resolution_notes' => 'Duplicate click from another admin.',
        ]);

        $defect->refresh();
        $this->assertTrue($defect->resolved);
        $this->assertSame('resolved', $defect->action_status);
        $this->assertNotNull($defect->resolved_at);
        $this->assertSame($this->reviewer->id, $defect->resolved_by_user_id);
        $this->assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('event_type', 'resolved')->count());

        foreach ([$this->alpha, $this->beta] as $participant) {
            $this->assertSame(1, $this->sentCount($participant, 'issue_resolved'), "{$participant->name} must be told exactly once.");
        }
        $this->assertSame(0, $this->sentCount($this->reviewer, 'issue_resolved'));
        $ledger = DB::table('inspection_notification_deliveries')->where('event_key', 'like', 'defect:'.$defect->id.':resolved:%')->get();
        $this->assertCount(2, $ledger);
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->beta->id], $ledger->pluck('recipient_user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['dispatched'], $ledger->pluck('status')->unique()->values()->all());

        $this->actAs($this->alpha);
        $this->assertSame([], $this->getJson($this->checklistUrl())->assertOk()->json('open_findings'));

        // What each member recorded that day is untouched by the later resolution.
        $this->assertSame($beforeResults, [$first->fresh()->results, $second->fresh()->results]);
        $this->assertSame('Missing', $first->fresh()->results[0]['items'][0]['status']);
        $this->assertSame($beforeObservations, ApparatusDefectObservation::query()->orderBy('id')->get(['id', 'observation', 'reported_status', 'notes'])->toArray());
        $this->assertSame('In Service', $this->unit->fresh()->status, 'Resolving a defect never changes the unit status.');
    }

    // ---------------------------------------------------------------- scenario F

    public function test_a_mileage_decrease_is_held_and_never_touches_the_authoritative_reading(): void
    {
        ['inspection' => $inspection, 'hold' => $hold] = $this->heldRollback();

        $this->assertSame('rollback', $hold->reason);
        $this->assertTrue($hold->blocks_acceptance);
        $this->assertSame('open', $hold->status);
        $this->assertSame(900, (int) $hold->submitted_value);
        $this->assertSame(1000, (int) $hold->authoritative_value);
        $this->assertSame('Needs approval', $inspection->displayStatus());
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours, 'The undisputed meter is still applied.');
        $this->assertGreaterThanOrEqual(1, $this->sentCount($this->reviewer, 'inspection_review_required'));
        $this->assertSame('Needs approval', $this->workspaceStatus($inspection));

        // Approving is refused until the meter is decided.
        try {
            app(InspectionReviewService::class)->approve($inspection->id, $this->reviewer);
            $this->fail('Approve must be refused while a meter hold is open.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('meter', strtolower($exception->errors()['inspection'][0]));
        }
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
    }

    public function test_dismissing_the_rollback_keeps_the_reading_audits_the_decision_and_releases_the_inspection(): void
    {
        ['inspection' => $inspection, 'hold' => $hold] = $this->heldRollback();

        $decided = app(ApparatusInspectionExceptionService::class)->reconcile($hold->id, $this->reviewer, [
            'action' => 'dismiss', 'reason' => 'Member read the trip meter by mistake.', 'expected_current_value' => 1000,
        ], $inspection->id);

        $this->assertSame('dismissed', $decided->status);
        $inspection->refresh();
        $this->assertSame('approved', $inspection->review_status);
        $this->assertSame('accepted', $inspection->processing_status);
        $this->assertSame(1000, $this->unit->fresh()->current_miles);

        $events = ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->orderBy('id')->get();
        $decision = $events->firstWhere('status', 'dismissed');
        $this->assertNotNull($decision);
        $this->assertSame($this->reviewer->id, $decision->changed_by_user_id);
        $this->assertSame('Member read the trip meter by mistake.', $decision->internal_note);
        $this->assertSame('dismiss', $decision->metadata['action']);
        $release = $events->firstWhere('status', 'accepted');
        $this->assertNotNull($release, 'Releasing the last hold must leave its own audit event.');
        $this->assertSame($this->reviewer->id, $release->metadata['released_by']);

        // The decision is idempotent.
        app(ApparatusInspectionExceptionService::class)->reconcile($hold->id, $this->reviewer, [
            'action' => 'dismiss', 'reason' => 'Member read the trip meter by mistake.', 'expected_current_value' => 1000,
        ], $inspection->id);
        $this->assertSame($events->count(), ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->count());
    }

    public function test_accepting_a_lower_reading_needs_an_explicit_meter_reset_confirmation_and_is_audited(): void
    {
        ['inspection' => $inspection, 'hold' => $hold] = $this->heldRollback();
        $service = app(ApparatusInspectionExceptionService::class);

        try {
            $service->reconcile($hold->id, $this->reviewer, ['action' => 'accept_submitted', 'reason' => 'Meter was replaced.', 'expected_current_value' => 1000]);
            $this->fail('A lower reading must not be accepted silently.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_meter_reset', $exception->errors());
        }
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
        $this->assertSame('pending_review', $inspection->fresh()->review_status);

        $service->reconcile($hold->id, $this->reviewer, [
            'action' => 'accept_submitted', 'reason' => 'Meter was replaced.', 'expected_current_value' => 1000, 'confirm_meter_reset' => true,
        ]);

        $this->assertSame(900, $this->unit->fresh()->current_miles);
        $decision = ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->where('status', 'resolved')->sole();
        $this->assertTrue($decision->metadata['meter_reset']);
        $this->assertSame($this->reviewer->id, $decision->changed_by_user_id);
        $this->assertSame('approved', $inspection->fresh()->review_status);
    }

    public function test_correcting_the_reading_applies_the_verified_value_and_releases_the_inspection(): void
    {
        ['inspection' => $inspection, 'hold' => $hold] = $this->heldRollback();

        app(ApparatusInspectionExceptionService::class)->reconcile($hold->id, $this->reviewer, [
            'action' => 'correct', 'value' => 1030, 'reason' => 'Verified against the dash photo.', 'expected_current_value' => 1000,
        ]);

        $this->assertSame(1030, $this->unit->fresh()->current_miles);
        $this->assertSame('resolved', $hold->fresh()->status);
        $inspection->refresh();
        $this->assertSame('approved', $inspection->review_status);
        $this->assertSame('accepted', $inspection->processing_status);
        // The member's submitted evidence is never edited by the correction.
        $this->assertSame(900, $inspection->miles);
    }

    public function test_requesting_a_revision_keeps_the_hold_open_tells_the_member_and_the_member_answer_is_decided_later(): void
    {
        ['inspection' => $inspection, 'hold' => $hold] = $this->heldRollback();
        $service = app(ApparatusInspectionExceptionService::class);

        $service->reconcile($hold->id, $this->reviewer, [
            'action' => 'request_revision', 'reason' => 'Which odometer did you read?', 'expected_current_value' => 1000,
        ]);
        $service->reconcile($hold->id, $this->reviewer, [
            'action' => 'request_revision', 'reason' => 'Which odometer did you read?', 'expected_current_value' => 1000,
        ]);

        $this->assertSame('revision_requested', $hold->fresh()->status);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        $message = ApparatusIssueMessage::query()->where('apparatus_inspection_id', $inspection->id)->sole();
        $this->assertSame('member', $message->visibility);
        $this->assertSame('Which odometer did you read?', $message->body);
        $this->assertSame(1, $this->sentCount($this->alpha, 'clarification_requested'));

        $this->actAs($this->alpha);
        $html = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
        $this->assertStringContainsString('Which odometer did you read?', $html);

        $service->submitRevision($hold->id, $this->alpha, ['reason' => 'I read the dash odometer: 1010.', 'value' => 1010]);
        $this->assertSame('revision_submitted', $hold->fresh()->status);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status, 'A member answer alone never releases the hold.');

        $service->reconcile($hold->id, $this->reviewer, [
            'action' => 'correct', 'value' => 1010, 'reason' => 'Matches the member answer.', 'expected_current_value' => 1000,
        ]);
        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame(1010, $this->unit->fresh()->current_miles);
    }

    // ---------------------------------------------------------------- scenario G

    public function test_new_significant_damage_is_held_and_approving_it_never_changes_the_unit_status(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 1, 'Damaged', ['significant' => true, 'notes' => 'Cracked in half.']);
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()
                ->assertJsonPath('processing_status', 'held_for_review')->assertJsonPath('review_status', 'pending_review')->json('id'),
        );

        $defect = ApparatusDefect::query()->sole();
        $this->assertSame('significant', $defect->severity);
        $this->assertSame('damaged', $defect->issue_type);
        $hold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->sole();
        $this->assertSame('significant_damage', $hold->field);
        $this->assertSame('new_significant_damage', $hold->reason);
        $this->assertTrue($hold->blocks_acceptance);
        $this->assertSame([$defect->id], $hold->metadata['defect_ids']);
        // Findings are facts even while held: the next inspector sees them.
        $this->assertSame(1, ApparatusDefectObservation::query()->where('apparatus_defect_id', $defect->id)->count());
        $this->assertGreaterThanOrEqual(1, $this->sentCount($this->reviewer, 'inspection_review_required'));
        $this->assertSame(1, $this->sentCount($this->reviewer, 'issue_escalated'));

        $approved = app(InspectionReviewService::class)->approve($inspection->id, $this->reviewer, 'Reviewed the photo.', false);

        $this->assertSame('approved', $approved->review_status);
        $this->assertSame('accepted', $approved->processing_status);
        $this->assertSame('resolved', $hold->fresh()->status);
        $this->assertSame('In Service', $this->unit->fresh()->status, 'Approval never changes operational status.');
        $this->assertSame(0, DB::table('apparatus_operational_status_events')->count());
        $this->assertSame(1, $this->sentCount($this->alpha, 'inspection_decided'));
        $this->assertFalse($defect->fresh()->resolved, 'Approving an inspection does not resolve its defects.');
    }

    public function test_known_significant_damage_confirmed_again_by_another_member_is_not_held_again(): void
    {
        $payload = $this->payload();
        $this->markItem($payload, 0, 1, 'Damaged', ['significant' => true, 'notes' => 'Cracked in half.']);
        $this->actAs($this->alpha);
        $first = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        app(InspectionReviewService::class)->approve($first->id, $this->reviewer);

        $this->travel(1)->days();
        $this->actAs($this->beta);
        $again = $this->payload(null, 1050, 405.0);
        $this->markItem($again, 0, 1, 'Damaged', ['significant' => true, 'notes' => 'Same crack.']);
        $this->submit($again)->assertCreated()->assertJsonPath('processing_status', 'accepted')->assertJsonPath('review_status', 'approved');

        $this->assertSame(1, ApparatusDefect::query()->count());
        $this->assertSame(1, ApparatusInspectionException::query()->count(), 'Only the original report created a hold.');
    }

    public function test_a_routine_damage_report_is_accepted_and_a_later_significant_report_of_the_same_item_escalates_and_holds(): void
    {
        $this->actAs($this->alpha);
        $routine = $this->payload();
        $this->markItem($routine, 0, 1, 'Damaged', ['notes' => 'Small dent.']);
        $this->submit($routine)->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $defect = ApparatusDefect::query()->sole();
        $this->assertSame('routine', $defect->severity);

        $this->travel(1)->days();
        $this->actAs($this->beta);
        $worse = $this->payload(null, 1050, 405.0);
        $this->markItem($worse, 0, 1, 'Damaged', ['significant' => true, 'notes' => 'The dent became a crack.']);
        $this->submit($worse)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        $this->assertSame(1, ApparatusDefect::query()->count());
        $this->assertSame('significant', $defect->fresh()->severity);
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'escalated')->count());
        $this->assertSame('significant_damage', ApparatusInspectionException::query()->sole()->field);
        $this->assertSame(1, $this->sentCount($this->reviewer, 'issue_escalated'));
    }

    // ---------------------------------------------------------------- scenario H

    public function test_a_member_review_flag_holds_the_inspection_notifies_reviewers_and_approval_releases_it(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['review_flag'] = [
            'explanation' => 'The pump panel gauge reads strangely after warm-up.',
            'items' => [$this->itemRef($payload, 0, 0), $this->itemRef($payload, 0, 1), $this->itemRef($payload, 0, 0)],
        ];
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()
                ->assertJsonPath('processing_status', 'held_for_review')->assertJsonPath('review_status', 'pending_review')->json('id'),
        );

        $this->assertSame('The pump panel gauge reads strangely after warm-up.', $inspection->review_flag_explanation);
        $this->assertCount(2, $inspection->review_flag_items, 'Duplicate item references collapse.');
        $this->assertArrayHasKey('compartment', $inspection->review_flag_items[0]);
        $hold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->sole();
        $this->assertSame('member_review_flag', $hold->field);
        $this->assertSame('member_flagged', $hold->reason);
        $this->assertSame('The pump panel gauge reads strangely after warm-up.', $hold->metadata['explanation']);
        $this->assertGreaterThanOrEqual(1, $this->sentCount($this->reviewer, 'inspection_review_required'));
        $this->assertSame(0, ApparatusDefect::query()->count());

        $this->actAs($this->alpha);
        $html = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
        $this->assertStringContainsString('Needs approval', $html);
        $this->assertStringContainsString('You flagged this for review', $html);

        $approved = app(InspectionReviewService::class)->approve($inspection->id, $this->reviewer, 'Gauge looked normal on inspection.');
        $this->assertSame('approved', $approved->review_status);
        $this->assertSame('accepted', $approved->processing_status);
        $this->assertSame('resolved', $hold->fresh()->status);
        $this->assertSame(1, $this->sentCount($this->alpha, 'inspection_decided'));
        $event = ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->where('status', 'approved')->sole();
        $this->assertSame($this->reviewer->id, $event->changed_by_user_id);
        $this->assertSame('Gauge looked normal on inspection.', $event->internal_note);
    }

    // ------------------------------------------------------------------- replay

    public function test_replaying_the_same_submission_returns_the_same_receipt_and_creates_nothing_new(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 1, 'Damaged', ['significant' => true, 'notes' => 'Cracked.']);
        $payload['review_flag'] = ['explanation' => 'Please look at the cracked housing.', 'items' => [$this->itemRef($payload, 0, 1)]];

        $first = $this->submit($payload)->assertCreated();
        $counts = $this->tableCounts();
        $sent = $this->totalSent();
        $this->assertGreaterThan(0, $sent);

        $replay = $this->submit($payload)->assertOk();
        $this->assertSame($first->json('id'), $replay->json('id'));
        $this->assertSame($first->json('inspection_reference'), $replay->json('inspection_reference'));
        $this->assertSame($first->json('processing_status'), $replay->json('processing_status'));
        $this->assertSame($counts, $this->tableCounts(), 'A replay must not add defects, observations, events, holds or messages.');
        $this->assertSame($sent, $this->totalSent(), 'A replay must not notify anyone again.');

        // The same id with different content is refused, not merged.
        $changed = $payload;
        $changed['review_flag']['explanation'] = 'A different explanation.';
        $this->submit($changed)->assertConflict()->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');
        $this->assertSame($counts, $this->tableCounts());
    }

    public function test_an_offline_submission_replayed_after_a_newer_accepted_inspection_is_superseded_and_never_held(): void
    {
        // The queued checkout was taken against 1000 mi / 400 h.
        $this->actAs($this->alpha);
        $offline = $this->payload(null, 1020, 401.2);

        $this->actAs($this->beta);
        $this->submit($this->payload(null, 1100, 450.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertSame(1100, $this->unit->fresh()->current_miles);

        $this->actAs($this->alpha);
        $this->submit($offline)->assertCreated()->assertJsonPath('processing_status', 'accepted')->assertJsonPath('review_status', 'approved');

        $this->assertSame(0, ApparatusInspectionException::query()->count());
        $this->assertSame(1100, $this->unit->fresh()->current_miles, 'The newer reading stays authoritative.');
        $this->assertSame('450.0', $this->unit->fresh()->current_engine_hours);
        $event = ApparatusInspectionReviewEvent::query()->orderByDesc('id')->first();
        $this->assertSame(['engine_hours' => 'superseded', 'miles' => 'superseded'], $event->metadata['meter_outcomes']);
        $this->assertSame(1020, ApparatusInspection::query()->orderByDesc('id')->first()->miles, 'The member reading is kept as evidence.');
    }

    // ---------------------------------------------------- vehicle swap + holds

    public function test_a_swap_hold_with_a_meter_rollback_and_a_member_flag_stays_pending_until_every_hold_is_decided(): void
    {
        $fleet = $this->newReviewer('Fleet Super');
        $fleet->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['physical_vehicle'] = ['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed', 'note' => 'E3 is in the shop.'];
        $this->setField($payload, 'vehicle_num', 'V-303');
        $payload['review_flag'] = ['explanation' => 'Different truck than usual.', 'items' => []];

        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'pending_vehicle_swap')->json('id'),
        );
        // Nothing is applied while Fleet decides.
        $this->assertSame(0, ApparatusInspectionException::query()->count());
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
        $this->assertSame(5000, $this->frontline->fresh()->current_miles);

        // Approving the ordinary way is refused while the swap is undecided.
        try {
            app(InspectionReviewService::class)->approve($inspection->id, $this->reviewer);
            $this->fail('A swap-held checkout must not be approved through the ordinary path.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('vehicle swap', strtolower($exception->getMessage()));
        }

        $request = ApparatusVehicleSwapRequest::query()->sole();
        app(VehicleSwapService::class)->decide($request->id, $fleet, VehicleSwapService::DECISION_APPROVE_SWAP);

        $inspection->refresh();
        $this->assertSame('held_for_review', $inspection->processing_status);
        $this->assertSame('pending_review', $inspection->review_status);
        $this->assertSame($this->frontline->id, $inspection->processed_apparatus_id, 'Findings apply to the confirmed truck.');
        $holds = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['engine_hours', 'member_review_flag', 'miles'], $holds->pluck('field')->all());

        $service = app(ApparatusInspectionExceptionService::class);
        foreach (['miles', 'engine_hours'] as $field) {
            $hold = $holds->firstWhere('field', $field);
            $this->assertSame('pending_review', $inspection->fresh()->review_status);
            $service->reconcile($hold->id, $this->reviewer, [
                'action' => 'dismiss', 'reason' => 'Keep the fleet reading.',
                'expected_current_value' => $this->frontline->fresh()->getAttribute('current_'.$field),
            ]);
        }
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status, 'The member flag is still undecided.');
        $this->assertSame('pending_review', $inspection->fresh()->review_status);

        app(InspectionReviewService::class)->approve($inspection->id, $this->reviewer, 'Flag reviewed.');
        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        $this->assertSame(0, ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->whereNotIn('status', ['resolved', 'dismissed'])->count());
    }

    public function test_a_checkout_held_for_a_vehicle_swap_renders_for_the_member_the_reviewer_and_the_pdf(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['physical_vehicle'] = ['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed', 'note' => 'E3 is in the shop.'];
        $this->setField($payload, 'vehicle_num', 'V-303');
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'pending_vehicle_swap')->json('id'),
        );

        $html = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
        $this->assertStringContainsString('Needs approval', $html);
        $this->assertStringContainsString('Fleet is confirming which vehicle you inspected', $html);
        $this->get(route('employee.vehicle-inspections.pdf', $inspection))->assertOk();

        $workspace = app(InspectionWorkspace::class)->build($inspection, $this->reviewer);
        $this->assertSame('Held: vehicle swap', $workspace['status']['label']);
        $this->actAs($this->reviewer);
        $this->get('/admin/inspections/'.$inspection->id)->assertOk();
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    #[DataProvider('inspectionStates')]
    public function test_every_inspection_state_renders_for_the_member_the_reviewer_and_the_pdf(string $review, ?string $processing, array $shape): void
    {
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->unit->id,
            'processed_apparatus_id' => ($shape['other_truck'] ?? false) ? $this->frontline->id : (($shape['unprocessed'] ?? false) ? null : $this->unit->id),
            'actor_user_id' => $this->alpha->id, 'employee_id' => $this->alpha->employee_profile_id,
            'operator_name' => 'Alpha Member', 'rank' => 'Firefighter', 'shift' => 'A',
            'unit_number' => 'V-202', 'vehicle_number' => 'V-202', 'designation_at_time' => 'E2',
            'inspection_reference' => 'INS-STATE-1', 'review_status' => $review, 'processing_status' => $processing,
            'results' => $shape['results'] ?? [['id' => 'cab', 'name' => 'Cab', 'items' => [
                ['id' => 'cab-radio', 'name' => 'Radio battery', 'status' => 'Missing', 'notes' => 'gone', 'observed' => true],
                ['id' => 'cab-mirror', 'name' => 'Mirror', 'status' => 'Damaged', 'notes' => 'cracked', 'observed' => true],
                ['id' => 'cab-horn', 'name' => 'Horn', 'status' => 'Present', 'observed' => true],
            ]]],
            'pending_effects' => $shape['pending_effects'] ?? null,
            'review_flag_explanation' => $shape['flag'] ?? null,
            'review_flag_items' => isset($shape['flag']) ? [['compartment_id' => 'cab', 'item_id' => 'cab-horn', 'compartment' => 'Cab', 'item' => 'Horn']] : null,
            'completed_at' => now(),
        ]);
        foreach ($shape['holds'] ?? [] as [$field, $reason, $status]) {
            ApparatusInspectionException::query()->create([
                'apparatus_inspection_id' => $inspection->id, 'apparatus_id' => $this->unit->id, 'field' => $field, 'reason' => $reason,
                'status' => $status, 'blocks_acceptance' => $reason !== 'follow_up', 'submitted_value' => 900, 'authoritative_value' => 1000,
            ]);
        }
        if ($shape['with_defect'] ?? true) {
            $defect = ApparatusDefect::query()->create([
                'apparatus_id' => $this->unit->id, 'apparatus_inspection_id' => $inspection->id, 'compartment' => 'Cab', 'item' => 'Radio battery',
                'status' => 'open', 'issue_type' => 'missing', 'reported_date' => now()->toDateString(), 'compartment_key' => 'cab', 'item_key' => 'cab-radio',
                'first_reported_inspection_id' => $inspection->id, 'first_reported_by_user_id' => $this->alpha->id, 'first_reported_at' => now(),
            ]);
            ApparatusDefectObservation::query()->create([
                'apparatus_defect_id' => $defect->id, 'apparatus_inspection_id' => $inspection->id, 'actor_user_id' => $this->alpha->id,
                'observation' => 'first_reported', 'reported_status' => 'Missing', 'notes' => 'gone',
            ]);
            ApparatusDefectParticipant::query()->create([
                'apparatus_defect_id' => $defect->id, 'user_id' => $this->alpha->id, 'employee_id' => $this->alpha->employee_profile_id,
                'enrollment_source' => 'reported', 'first_inspection_id' => $inspection->id, 'last_inspection_id' => $inspection->id,
                'first_participated_at' => now(), 'last_participated_at' => now(),
            ]);
        }

        $this->actAs($this->alpha);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk();
        $this->get(route('employee.vehicle-inspections.pdf', $inspection))->assertOk();
        $this->actAs($this->reviewer);
        $this->get('/admin/inspections/'.$inspection->id)->assertOk();
    }

    /** @return array<string, array{0: string, 1: ?string, 2: array<string, mixed>}> */
    public static function inspectionStates(): array
    {
        return [
            'accepted' => ['approved', 'accepted', []],
            'accepted with a non blocking follow up' => ['approved', 'accepted_with_exception', ['holds' => [['miles', 'follow_up', 'open']]]],
            'held for a meter rollback' => ['pending_review', 'held_for_review', ['holds' => [['miles', 'rollback', 'open']]]],
            'held with clarification requested' => ['pending_review', 'held_for_review', ['holds' => [['miles', 'rollback', 'revision_requested']]]],
            'held with the revision submitted' => ['pending_review', 'held_for_review', ['holds' => [['engine_hours', 'rollback', 'revision_submitted']]]],
            'held for a member flag' => ['pending_review', 'held_for_review', ['flag' => 'Please look.', 'holds' => [['member_review_flag', 'member_flagged', 'open']]]],
            'held for significant damage' => ['pending_review', 'held_for_review', ['holds' => [['significant_damage', 'new_significant_damage', 'open']]]],
            'held with every hold at once' => ['pending_review', 'held_for_review', ['flag' => 'Look.', 'holds' => [
                ['miles', 'rollback', 'open'], ['engine_hours', 'rollback', 'open'], ['member_review_flag', 'member_flagged', 'open'], ['significant_damage', 'new_significant_damage', 'open'],
            ]]],
            'held with a decided hold' => ['pending_review', 'held_for_review', ['holds' => [['miles', 'rollback', 'dismissed'], ['member_review_flag', 'member_flagged', 'open']]]],
            'rejected' => ['rejected', 'rejected', ['holds' => [['miles', 'rollback', 'dismissed']]]],
            'legacy pending with pending effects' => ['pending_review', null, ['unprocessed' => true, 'pending_effects' => [
                'has_critical_defects' => true, 'defects' => [['compartment' => 'Cab', 'item' => 'Radio battery', 'status' => 'Missing', 'notes' => 'gone']],
            ]]],
            'legacy pending with no pending effects' => ['pending_review', null, ['unprocessed' => true, 'with_defect' => false]],
            'legacy approved' => ['approved', null, ['unprocessed' => true, 'with_defect' => false]],
            'held for a vehicle swap' => ['pending_review', 'pending_vehicle_swap', ['unprocessed' => true]],
            'processed against a different truck' => ['approved', 'accepted', ['other_truck' => true]],
            'no stored results' => ['approved', 'accepted', ['results' => [], 'with_defect' => false]],
            'partly malformed stored results' => ['approved', 'accepted', ['results' => ['not an array', ['items' => [['name' => 'No status'], 'nor this']]], 'with_defect' => false]],
        ];
    }

    public function test_admin_list_pages_render_with_held_inspections_and_the_legacy_page_redirects_to_the_canonical_one(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload(null, 900, 401.2);
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $payload['review_flag'] = ['explanation' => 'Check the charger.', 'items' => [$this->itemRef($payload)]];
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );
        $defect = ApparatusDefect::query()->sole();
        $hold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->where('field', 'miles')->sole();

        $this->actAs($this->reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['inspections', 'defects', 'apparatus-inspection-exceptions'] as $slug) {
            $this->get(route("filament.admin.resources.{$slug}.index"))->assertOk();
        }
        Livewire::test(ListInspections::class)->loadTable()->assertSuccessful()->assertCanSeeTableRecords([$inspection]);
        Livewire::test(ListDefects::class)->loadTable()->assertSuccessful()->assertCanSeeTableRecords([$defect])
            ->assertTableActionVisible('source_inspection', $defect);
        Livewire::test(ListInspectionExceptions::class)->loadTable()->assertSuccessful()->assertCanSeeTableRecords([$hold]);

        $canonical = route('filament.admin.resources.inspections.view', ['record' => $inspection->id]);
        $this->get(route('filament.admin.resources.apparatuses.view-inspection', ['record' => $this->unit->id, 'inspection' => $inspection->id]))
            ->assertRedirect($canonical);
        $this->get(route('filament.admin.resources.apparatuses.view', ['record' => $this->unit->id]))->assertOk();
    }

    public function test_notification_links_land_on_pages_that_contain_the_anchor_they_name(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 1, 'Damaged', ['significant' => true, 'notes' => 'Cracked.']);
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        $defect = ApparatusDefect::query()->sole();

        $review = Notification::sent($this->reviewer, InspectionEventNotification::class)->firstWhere('kind', 'inspection_review_required');
        $escalated = Notification::sent($this->reviewer, InspectionEventNotification::class)->firstWhere('kind', 'issue_escalated');
        $this->assertSame('/admin/inspections/'.$inspection->id.'#approval', $review->url);
        $this->assertSame('/admin/inspections/'.$inspection->id.'#issue-'.$defect->id, $escalated->url);

        $this->actAs($this->reviewer);
        $admin = $this->get(strtok($review->url, '#'))->assertOk()->getContent();
        $this->assertStringContainsString('id="approval"', $admin);
        $this->assertStringContainsString('id="issue-'.$defect->id.'"', $admin);

        app(InspectionReviewService::class)->approve($inspection->id, $this->reviewer);
        app(DefectCorrectiveActionService::class)->acknowledge($defect->id, $this->reviewer, ['member_message' => 'We have it.']);
        foreach (['inspection_decided', 'issue_member_update'] as $kind) {
            $notification = Notification::sent($this->alpha, InspectionEventNotification::class)->firstWhere('kind', $kind);
            $this->assertNotNull($notification, "{$kind} must reach the owner.");
            $this->assertStringStartsWith('/employee/my-vehicle-inspections/'.$inspection->id, $notification->url);
        }
        $this->actAs($this->alpha);
        $member = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
        $this->assertStringContainsString('id="issue-'.$defect->id.'"', $member);
    }

    public function test_rejecting_a_held_inspection_dismisses_every_hold_keeps_the_defects_and_never_applies_the_held_meter(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload(null, 900, 401.2);
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $payload['review_flag'] = ['explanation' => 'Check the charger.', 'items' => [$this->itemRef($payload)]];
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );
        $this->assertSame(2, ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->count());

        $rejected = app(InspectionReviewService::class)->reject($inspection->id, $this->reviewer, 'Wrong vehicle was inspected.');

        $this->assertSame('rejected', $rejected->review_status);
        $this->assertSame('rejected', $rejected->processing_status);
        $this->assertSame(['dismissed'], ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->pluck('status')->unique()->values()->all());
        $this->assertSame(1000, $this->unit->fresh()->current_miles, 'The held rollback reading is never applied.');
        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $this->unit->id)->unresolved()->count(), 'Recorded findings are facts and stay.');
        $event = ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->where('status', 'rejected')->sole();
        $this->assertSame($this->reviewer->id, $event->changed_by_user_id);
        $this->assertSame('Wrong vehicle was inspected.', $event->internal_note);
        $this->assertSame(1, $this->sentCount($this->alpha, 'inspection_decided'));
        $this->assertSame('Rejected', $rejected->displayStatus());
    }

    public function test_resolution_skips_a_participant_whose_account_is_no_longer_active(): void
    {
        ['defect' => $defect] = $this->reportedByAlphaConfirmedByBeta();
        $this->beta->forceFill(['account_status' => \App\Enums\AccountStatus::Disabled])->save();

        app(DefectCorrectiveActionService::class)->resolve($defect->id, $this->reviewer, [
            'resolution_type' => 'repair_completed', 'resolution_notes' => 'Done.',
        ]);

        $this->assertSame(1, $this->sentCount($this->alpha, 'issue_resolved'));
        $this->assertSame(0, $this->sentCount($this->beta, 'issue_resolved'));
        $this->assertSame([$this->alpha->id], DB::table('inspection_notification_deliveries')->where('event_key', 'like', 'defect:'.$defect->id.':resolved:%')->pluck('recipient_user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_dashboard_queues_count_each_inspection_and_issue_once_and_move_an_acknowledged_issue_to_corrective_actions(): void
    {
        ['defect' => $defect] = $this->reportedByAlphaConfirmedByBeta();
        $gamma = $this->newMember('Gamma Member');
        $this->actAs($gamma);
        $this->submit($this->payload(null, 900, 410.0))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        $queues = $this->dashboardQueues();
        $this->assertSame(1, $queues['approvals']['count'], 'One held inspection.');
        $this->assertStringEndsWith('#approval', $queues['approvals']['rows'][0]['url']);
        $this->assertSame(1, $queues['issues']['count'], 'One defect seen by two inspections is one issue.');
        $this->assertStringEndsWith('#issue-'.$defect->id, $queues['issues']['rows'][0]['url']);
        $this->assertSame(0, $queues['actions']['count']);
        $this->assertSame(2, $queues['recent']['count'], 'Only the two accepted checkouts are completed.');

        app(DefectCorrectiveActionService::class)->acknowledge($defect->id, $this->reviewer);
        $queues = $this->dashboardQueues();
        $this->assertSame(0, $queues['issues']['count']);
        $this->assertSame(1, $queues['actions']['count']);

        app(DefectCorrectiveActionService::class)->resolve($defect->id, $this->reviewer, ['resolution_type' => 'other', 'resolution_notes' => 'Done.']);
        $queues = $this->dashboardQueues();
        $this->assertSame(0, $queues['issues']['count'] + $queues['actions']['count']);
        $this->assertSame(1, $queues['approvals']['count'], 'The held inspection still waits for its meter decision.');
    }

    public function test_the_significant_marker_only_counts_on_a_damaged_item(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 0, 'Missing', ['significant' => true, 'notes' => 'Gone.']);

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame('routine', ApparatusDefect::query()->sole()->severity);
        $this->assertSame(0, ApparatusInspectionException::query()->count());
    }

    public function test_a_member_reply_reaches_the_reviewers_and_a_reviewer_reply_reaches_the_member(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        $defect = ApparatusDefect::query()->sole();

        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(MyVehicleInspectionView::class, ['inspection' => $inspection->id])
            ->set('issueReplies.'.$defect->id, 'Still missing as of this morning.')
            ->call('sendIssueReply', $defect->id)
            ->assertHasNoErrors();

        $reply = ApparatusIssueMessage::query()->sole();
        $this->assertSame('member', $reply->visibility);
        $this->assertSame($this->alpha->id, $reply->author_user_id);
        $this->assertSame($defect->id, $reply->apparatus_defect_id);
        $notification = Notification::sent($this->reviewer, InspectionEventNotification::class)->firstWhere('kind', 'member_reply');
        $this->assertNotNull($notification, 'Reviewers must hear about a member reply.');
        $this->assertSame('/admin/inspections/'.$inspection->id.'#issue-'.$defect->id, $notification->url);
        $this->assertSame(0, $this->sentCount($this->alpha, 'member_reply'));

        app(IssueMessageService::class)->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'Thanks, we are on it.']);
        $this->assertSame(1, $this->sentCount($this->alpha, 'issue_member_update'));
        $this->assertSame(0, $this->sentCount($this->reviewer, 'issue_member_update'));
    }

    // ------------------------------------------- issue lifecycle edge scenarios

    public function test_a_present_answer_from_a_stale_draft_after_the_issue_was_resolved_creates_no_observation_and_a_missing_answer_is_a_recurrence(): void
    {
        ['defect' => $defect] = $this->reportedByAlphaConfirmedByBeta();
        app(DefectCorrectiveActionService::class)->resolve($defect->id, $this->reviewer, [
            'resolution_type' => 'replacement_installed', 'resolution_notes' => 'Installed.',
        ]);
        $observationsBefore = ApparatusDefectObservation::query()->count();

        // Draft opened before the resolution, answering "Present".
        $this->actAs($this->alpha);
        $present = $this->payload(null, 1100, 410.0);
        $this->submit($present)->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $this->assertSame($observationsBefore, ApparatusDefectObservation::query()->count(), 'There is nothing left to verify on a resolved issue.');
        $this->assertTrue($defect->fresh()->resolved);
        $this->assertSame(1, ApparatusDefect::query()->count());

        // The same draft answering "Missing" is a NEW failure episode linked to the resolved one.
        $this->actAs($this->beta);
        $missing = $this->payload(null, 1110, 411.0);
        $this->markItem($missing, 0, 0, 'Missing', ['notes' => 'Gone again.']);
        $this->submit($missing)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(2, ApparatusDefect::query()->count());
        $recurrence = ApparatusDefect::query()->where('id', '!=', $defect->id)->sole();
        $this->assertSame($defect->id, $recurrence->recurrence_of_defect_id);
        $this->assertFalse($recurrence->resolved);
        $this->assertSame('reported', $recurrence->action_status);
        $this->assertTrue($defect->fresh()->resolved, 'The earlier episode stays resolved and keeps its history.');
        $this->assertSame($this->beta->id, $recurrence->first_reported_by_user_id);
        $this->assertSame(['reported'], ApparatusDefectParticipant::query()->where('apparatus_defect_id', $recurrence->id)->pluck('enrollment_source')->all());

        // A second resolution of a recurring item must not hit the old unique index.
        app(DefectCorrectiveActionService::class)->resolve($recurrence->id, $this->reviewer, [
            'resolution_type' => 'spare_issued', 'resolution_notes' => 'Spare issued.',
        ]);
        $this->assertTrue($recurrence->fresh()->resolved);
    }

    public function test_an_item_found_by_the_next_member_is_recorded_as_appears_corrected_and_is_not_auto_resolved(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Not in the charger.']);
        $this->submit($payload)->assertCreated();
        $defect = ApparatusDefect::query()->sole();

        $this->travel(1)->days();
        $this->actAs($this->beta);
        $this->submit($this->payload(null, 1040, 403.0))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $defect->refresh();
        $this->assertFalse($defect->resolved, 'A Present answer must never close an issue by itself.');
        $this->assertSame('reported', $defect->action_status);
        $latest = ApparatusDefectObservation::query()->where('apparatus_defect_id', $defect->id)->orderByDesc('id')->first();
        $this->assertSame('appears_corrected', $latest->observation);
        $this->assertSame($this->beta->id, $latest->actor_user_id);
        $this->assertSame('verified_corrected', ApparatusDefectParticipant::query()->where('user_id', $this->beta->id)->sole()->enrollment_source);
        Notification::assertNothingSent();

        // The issue is still listed for the next checkout.
        $this->assertCount(1, $this->getJson($this->checklistUrl())->assertOk()->json('open_findings'));

        // Logistics verifies the correction, then it is resolved.
        app(DefectCorrectiveActionService::class)->verifyCorrection($defect->id, $this->reviewer);
        $this->assertTrue($defect->fresh()->resolved);
        $this->assertSame('item_located', $defect->fresh()->resolution_type);
        $this->assertSame(1, $this->sentCount($this->alpha, 'issue_resolved'));
        $this->assertSame(1, $this->sentCount($this->beta, 'issue_resolved'));
    }

    // ------------------------------------------------------------ legacy & files

    public function test_a_legacy_inspection_without_an_actor_still_processes_and_enrolls_nobody(): void
    {
        $checklist = app(DailyCheckoutChecklistResolver::class)->resolve($this->unit)['checklist'];
        $compartment = $checklist['compartments'][0];
        $item = $compartment['items'][0];
        $itemId = $item['id'] ?? $compartment['id'].'-item-1';
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->unit->id, 'operator_name' => 'Legacy Operator', 'rank' => 'Firefighter', 'shift' => 'A',
            'engine_hours' => 401.2, 'miles' => 1020, 'review_status' => 'pending_review', 'completed_at' => now(),
            'results' => [['id' => $compartment['id'], 'name' => $compartment['name'] ?? $compartment['title'], 'items' => [
                ['id' => $itemId, 'name' => $item['name'], 'status' => 'Missing', 'notes' => 'Legacy note', 'observed' => true],
            ]]],
        ]);
        $this->assertNull($inspection->actor_user_id);

        DB::transaction(function () use ($inspection, $checklist): void {
            $locked = Apparatus::query()->lockForUpdate()->findOrFail($this->unit->id);
            app(ApparatusInspectionProcessingService::class)->process($inspection, $locked, null, $checklist);
        });

        $inspection->refresh();
        $this->assertSame('approved', $inspection->review_status);
        $this->assertSame('accepted', $inspection->processing_status);
        $defect = ApparatusDefect::query()->sole();
        $this->assertNull($defect->first_reported_by_user_id);
        $this->assertSame(0, ApparatusDefectParticipant::query()->count());
        $this->assertSame(1, ApparatusDefectObservation::query()->count());
        $this->assertNull(ApparatusDefectObservation::query()->sole()->actor_user_id);
        // Reviewers can render it; the reporter falls back to the free-text snapshot.
        $workspace = app(InspectionWorkspace::class)->build($inspection, $this->reviewer);
        $this->assertCount(1, $workspace['defects']);
        $this->assertSame('Legacy Operator', $workspace['defects'][0]['first_reported_by']);
    }

    public function test_the_legacy_approval_path_records_defects_without_overwriting_an_existing_issue_or_enrolling_anyone(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'ORIGINAL origin note.']);
        $this->submit($payload)->assertCreated();
        $defect = ApparatusDefect::query()->sole();
        app(DefectCorrectiveActionService::class)->acknowledge($defect->id, $this->reviewer, ['internal_note' => 'seen']);
        $originNotes = $defect->fresh()->notes;
        $originInspection = $defect->apparatus_inspection_id;

        $legacy = ApparatusInspection::query()->create([
            'apparatus_id' => $this->unit->id, 'operator_name' => 'Legacy', 'rank' => 'Firefighter', 'shift' => 'A',
            'review_status' => 'pending_review', 'results' => [], 'completed_at' => now(),
            'pending_effects' => ['has_critical_defects' => true, 'defects' => [[
                'compartment' => $payload['defects'][0]['compartment'], 'item' => $payload['defects'][0]['item'],
                'status' => 'Missing', 'notes' => 'LEGACY replay note must not replace the origin.',
            ]]],
        ]);

        app(ApparatusInspectionApprovalService::class)->approve($legacy->id, $this->reviewer);

        $this->assertSame(1, ApparatusDefect::query()->count());
        $defect->refresh();
        $this->assertSame($originNotes, $defect->notes);
        $this->assertSame($originInspection, $defect->apparatus_inspection_id);
        $this->assertSame('acknowledged', $defect->action_status, 'A replay must not reset the workflow status.');
        $this->assertSame('approved', $legacy->fresh()->review_status);
    }

    public function test_missing_photo_files_never_break_the_admin_member_or_pdf_views(): void
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Photographed.', 'photo' => self::SIGNATURE_PNG]);
        $payload['defects'][0]['photo'] = self::SIGNATURE_PNG;
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        $this->assertNotEmpty(Storage::disk('public')->allFiles());

        // Files vanish from disk (cleanup, restore from a partial backup).
        foreach (Storage::disk('public')->allFiles() as $file) {
            Storage::disk('public')->delete($file);
        }

        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk();
        $this->get(route('employee.vehicle-inspections.pdf', $inspection))->assertOk();
        $this->assertNotNull(ApparatusDefect::query()->sole()->photo_path);
        $this->assertTrue(ApparatusDefect::query()->sole()->hasMissingPhotoReference());

        $workspace = app(InspectionWorkspace::class)->build($inspection, $this->reviewer);
        $this->assertCount(1, $workspace['defects']);
        $key = $workspace['defects'][0]['photo_key'];
        if ($key !== null) {
            $this->get('/employee/vehicle-inspections/'.$inspection->id.'/images/'.$key)->assertNotFound();
        }
        $this->assertNull(app(EmployeeInspectionHistoryService::class)->embeddableImage('defects/does-not-exist.png', 1_500_000));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Alpha reports the missing battery; beta confirms it the next day.
     *
     * @return array{defect: ApparatusDefect, first: ApparatusInspection, second: ApparatusInspection}
     */
    private function reportedByAlphaConfirmedByBeta(): array
    {
        $this->actAs($this->alpha);
        $alphaPayload = $this->payload();
        $this->markItem($alphaPayload, 0, 0, 'Missing', ['notes' => 'Battery not in the radio charger.']);
        $first = ApparatusInspection::query()->findOrFail($this->submit($alphaPayload)->assertCreated()->json('id'));

        $this->travel(1)->days();
        $this->actAs($this->beta);
        $betaPayload = $this->payload(null, 1050, 405.0);
        $this->markItem($betaPayload, 0, 0, 'Missing', ['notes' => 'Still missing this morning.']);
        $second = ApparatusInspection::query()->findOrFail($this->submit($betaPayload)->assertCreated()->json('id'));

        return ['defect' => ApparatusDefect::query()->sole(), 'first' => $first, 'second' => $second];
    }

    /** @return array{inspection: ApparatusInspection, hold: ApparatusInspectionException} */
    private function heldRollback(): array
    {
        $this->actAs($this->alpha);
        $response = $this->submit($this->payload(null, 900, 401.2))->assertCreated()
            ->assertJsonPath('processing_status', 'held_for_review')->assertJsonPath('review_status', 'pending_review');
        $inspection = ApparatusInspection::query()->findOrFail($response->json('id'));

        return [
            'inspection' => $inspection,
            'hold' => ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->where('field', 'miles')->sole(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function dashboardQueues(): array
    {
        $this->actAs($this->reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(\App\Filament\Widgets\StationOperationsHubWidget::class)->instance()->getViewData()['department']['inspectionQueues'];
    }

    private function workspaceStatus(ApparatusInspection $inspection): string
    {
        return InspectionWorkspace::statusPresentation($inspection->fresh())['label'];
    }

    private function sentCount(User $user, string $kind): int
    {
        return Notification::sent($user, InspectionEventNotification::class)
            ->filter(static fn (InspectionEventNotification $notification): bool => $notification->kind === $kind)
            ->count();
    }

    private function totalSent(): int
    {
        $total = 0;
        foreach (Notification::sentNotifications() as $byId) {
            foreach ($byId as $byClass) {
                foreach ($byClass as $entries) {
                    $total += count($entries);
                }
            }
        }

        return $total;
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach ([
            'apparatus_inspections', 'apparatus_inspection_review_events', 'apparatus_inspection_exceptions', 'apparatus_defects',
            'apparatus_defect_observations', 'apparatus_defect_events', 'apparatus_defect_participants', 'apparatus_issue_messages',
            'inspection_notification_deliveries',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
