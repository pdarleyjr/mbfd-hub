<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Filament\Support\InspectionWorkspace;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\AiAutomation\Domains\VehicleAdapter;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Services\Inspections\IssueMessageService;
use App\Services\Inspections\MemberInspectionConversation;
use App\Support\Fleet\DailyCheckoutNotesFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * The vehicle adapter of the AI Response automation, on synthetic crews and checkouts submitted through the real API.
 * The assistant may only append one member-visible "AI Response"; every defect, inspection and unit field stays put.
 */
final class VehicleAdapterTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use RefreshDatabase;

    private User $alpha;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Queue::fake();
        $this->buildWorld();
        // Only this adapter, so the suite does not depend on the other domains' adapters being present.
        config(['ai_automation.adapters' => [VehicleAdapter::class]]);
        $this->alpha = $this->newMember('Alpha Member');
        $this->reviewer = $this->newReviewer('Logistics Reviewer');
    }

    private function adapter(): VehicleAdapter
    {
        return app(VehicleAdapter::class);
    }

    private function switchOn(): void
    {
        DB::table('ai_automation_settings')->update(['enabled' => true]);
    }

    private function intakeFor(string $type, int $id): AiIntakeEvent
    {
        return AiIntakeEvent::query()->firstOrCreate(
            ['source_type' => $type, 'source_id' => $id, 'event_key' => 'received'],
            ['domain' => 'vehicle', 'origin' => 'live', 'epoch' => 1, 'state' => AiIntakeEvent::STATE_QUEUED, 'needs_human' => true],
        );
    }

    private function inspectionIntake(ApparatusInspection $inspection): AiIntakeEvent
    {
        return $this->intakeFor(VehicleAdapter::SOURCE_INSPECTION, (int) $inspection->id);
    }

    /**
     * First plain checklist item (no typed value) that is, or is not, Captain 5 medical for this template.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int}
     */
    private function pick(array $payload, string $template, bool $medical): array
    {
        $listed = (array) config("captain5_medical_scope.{$template}.medical", []);
        $ambiguous = (array) config("captain5_medical_scope.{$template}.ambiguous", []);
        foreach ($payload['compartments'] as $c => $compartment) {
            foreach ($compartment['items'] as $i => $item) {
                if (array_key_exists('value', $item)) {
                    continue;
                }
                $isMedical = in_array($item['id'], $listed, true);
                if ($medical ? $isMedical : (! $isMedical && ! in_array($item['id'], $ambiguous, true))) {
                    return [$c, $i];
                }
            }
        }
        $this->fail('No suitable checklist item found.');
    }

    /**
     * Submit one checkout for $apparatus through the real endpoint and return the stored inspection.
     *
     * @param  list<array{0: int, 1: int, 2: string, 3?: array<string, mixed>}>  $marks  compartment, item, status, extra
     */
    private function checkout(array $marks, ?Apparatus $apparatus = null, ?int $miles = null, ?float $hours = null): ApparatusInspection
    {
        $apparatus ??= $this->unit;
        $this->actAs($this->alpha);
        $payload = $this->payload($apparatus, $miles ?? ((int) $apparatus->fresh()->current_miles + 20), $hours ?? ((float) $apparatus->fresh()->current_engine_hours + 1.2));
        foreach ($marks as $mark) {
            $this->markItem($payload, $mark[0], $mark[1], $mark[2], $mark[3] ?? []);
        }

        return ApparatusInspection::query()->findOrFail($this->submit($payload, $apparatus)->assertCreated()->json('id'));
    }

    /** One plain non-medical item reported Missing on the Engine 2 unit. */
    private function missingOnUnit(array $extra = ['notes' => 'Not in the cab.'], ?int $miles = null, ?float $hours = null): ApparatusInspection
    {
        $payload = $this->payload();
        [$c, $i] = $this->pick($payload, 'engine2', false);

        return $this->checkout([[$c, $i, 'Missing', $extra]], null, $miles, $hours);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'defects' => ApparatusDefect::query()->orderBy('id')->get()->toArray(),
            'inspections' => ApparatusInspection::query()->orderBy('id')->get()->toArray(),
            'apparatus' => Apparatus::query()->orderBy('id')->get()->toArray(),
            'events' => ApparatusDefectEvent::query()->orderBy('id')->get()->toArray(),
            'observations' => ApparatusDefectObservation::query()->orderBy('id')->get()->toArray(),
            'review_events' => ApparatusInspectionReviewEvent::query()->orderBy('id')->get()->toArray(),
            'participants' => ApparatusDefectParticipant::query()->orderBy('id')->get()->toArray(),
        ];
    }

    // ------------------------------------------------------------ the hooks

    public function test_a_checkout_records_exactly_one_intake_row_when_the_switch_is_on(): void
    {
        $this->switchOn();

        $inspection = $this->missingOnUnit();

        $row = AiIntakeEvent::query()->sole();
        $this->assertSame('vehicle', $row->domain);
        $this->assertSame(VehicleAdapter::SOURCE_INSPECTION, $row->source_type);
        $this->assertSame((int) $inspection->id, (int) $row->source_id);
        $this->assertSame('received', $row->event_key);
        $this->assertSame(AiIntakeEvent::STATE_QUEUED, $row->state);
        $this->assertSame(AiIntakeEvent::ORIGIN_LIVE, $row->origin);
    }

    public function test_nothing_is_recorded_while_the_switch_is_off(): void
    {
        $this->missingOnUnit();

        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_a_member_message_on_an_issue_thread_records_one_row_but_staff_and_corrections_do_not(): void
    {
        $this->switchOn();
        $inspection = $this->missingOnUnit();
        $defect = ApparatusDefect::query()->sole();
        AiIntakeEvent::query()->delete();
        $messages = app(IssueMessageService::class);

        $member = $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $defect->id, 'body' => 'Still not found.']);
        $row = AiIntakeEvent::query()->sole();
        $this->assertSame(VehicleAdapter::SOURCE_ISSUE_MESSAGE, $row->source_type);
        $this->assertSame((int) $member->id, (int) $row->source_id);

        // A correction, a staff message and a message on the general thread never record anything.
        $messages->correct($this->alpha, (int) $member->id, 'Still not found, sorry.');
        $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'Checking stock.']);
        $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'body' => 'General question.']);
        $this->assertSame(1, AiIntakeEvent::query()->count());
    }

    // ------------------------------------------------------------ facts

    public function test_facts_describe_a_new_missing_item_with_the_members_note(): void
    {
        $inspection = $this->missingOnUnit(['notes' => 'Not in the cab.']);

        $facts = $this->adapter()->facts($this->inspectionIntake($inspection));

        $this->assertTrue($facts->eligible());
        $this->assertSame('vehicle', $facts->domain);
        $this->assertSame('inspection_findings', $facts->kind);
        $this->assertSame((int) $this->alpha->id, $facts->requesterUserId);
        $this->assertCount(1, $facts->findings);
        $finding = $facts->findings[0];
        $this->assertSame(ApparatusDefect::query()->sole()->item, $finding->itemName);
        $this->assertSame('missing', $finding->condition);
        $this->assertTrue($finding->isNew);
        $this->assertFalse($finding->significant);
        $this->assertSame('Not in the cab.', $finding->note);
        $this->assertStringContainsString('Not in the cab.', (string) $facts->memberText);
    }

    public function test_facts_carry_damaged_and_significant_flags(): void
    {
        $payload = $this->payload();
        [$c, $i] = $this->pick($payload, 'engine2', false);
        $inspection = $this->checkout([[$c, $i, 'Damaged', ['notes' => 'Cracked housing.', 'significant' => true]]]);

        $facts = $this->adapter()->facts($this->inspectionIntake($inspection));

        $this->assertTrue($facts->eligible());
        $this->assertSame('damaged', $facts->findings[0]->condition);
        $this->assertTrue($facts->findings[0]->significant);
        $this->assertTrue($facts->findings[0]->isNew);
    }

    public function test_a_repeat_whose_issue_never_got_a_response_is_eligible_once_then_repeat_only(): void
    {
        $first = $this->missingOnUnit();
        $second = $this->missingOnUnit(['notes' => 'Still not there.']);

        $facts = $this->adapter()->facts($this->inspectionIntake($second));
        $this->assertTrue($facts->eligible(), 'Nobody has answered this issue yet, so it is still owed one receipt.');
        $this->assertFalse($facts->findings[0]->isNew);

        // The first checkout's receipt was sent: the repeat is now nothing new.
        $this->inspectionIntake($first)->forceFill(['state' => AiIntakeEvent::STATE_SENT])->save();
        $facts = $this->adapter()->facts($this->inspectionIntake($second));
        $this->assertSame('repeat_only', $facts->ineligibleReason);
        $this->assertFalse($facts->eligible());
    }

    public function test_a_repeat_whose_issue_staff_already_acknowledged_is_repeat_only(): void
    {
        $this->missingOnUnit();
        app(DefectCorrectiveActionService::class)->acknowledge(ApparatusDefect::query()->sole()->id, $this->reviewer, ['member_message' => 'On it.']);
        $second = $this->missingOnUnit();

        $this->assertSame('repeat_only', $this->adapter()->facts($this->inspectionIntake($second))->ineligibleReason);
    }

    public function test_a_clean_checkout_is_ineligible(): void
    {
        $this->actAs($this->alpha);
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($this->payload())->assertCreated()->json('id'));

        $this->assertSame('no_findings', $this->adapter()->facts($this->inspectionIntake($inspection))->ineligibleReason);
    }

    public function test_a_missing_source_and_a_test_excluded_checkout_are_ineligible(): void
    {
        $inspection = $this->missingOnUnit();

        $gone = $this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_INSPECTION, 987654));
        $this->assertSame('source_missing', $gone->ineligibleReason);

        ApparatusInspection::query()->whereKey($inspection->id)->update(['test_excluded_at' => now()]);
        $this->assertSame('test_excluded', $this->adapter()->facts($this->inspectionIntake($inspection))->ineligibleReason);
    }

    public function test_medical_and_rescue_findings_are_out_of_scope(): void
    {
        $payload = $this->payload();
        [$mc, $mi] = $this->pick($payload, 'engine2', true);
        [$nc, $ni] = $this->pick($payload, 'engine2', false);

        $medicalOnly = $this->checkout([[$mc, $mi, 'Missing', ['notes' => 'Not in the box.']]]);
        $this->assertSame('out_of_scope', $this->adapter()->facts($this->inspectionIntake($medicalOnly))->ineligibleReason);

        // Mixed: only the Logistics item is described; the medical one is left to Captain 5 without a trace.
        $mixed = $this->checkout([[$mc, $mi, 'Damaged', []], [$nc, $ni, 'Missing', ['notes' => 'Gone.']]]);
        $facts = $this->adapter()->facts($this->inspectionIntake($mixed));
        $this->assertTrue($facts->eligible());
        $this->assertCount(1, $facts->findings);
        $medicalName = ApparatusDefect::query()->where('item_key', $payload['compartments'][$mc]['items'][$mi]['id'])->value('item');
        $this->assertNotSame($medicalName, $facts->findings[0]->itemName);

        $rescue = Apparatus::query()->create([
            'station_id' => $this->unit->station_id, 'vin' => 'VIN-R1', 'assignment' => 'Station 2', 'unit_id' => 'R1', 'designation' => 'R1',
            'name' => 'Rescue 1', 'type' => 'Rescue', 'vehicle_number' => 'V-R1', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
            'current_engine_hours' => 100, 'current_miles' => 100,
        ]);
        $rescuePayload = $this->payload($rescue, 120, 101.0);
        [$rc, $ri] = $this->pick($rescuePayload, 'rescue', false);
        $onRescue = $this->checkout([[$rc, $ri, 'Missing', []]], $rescue);
        $this->assertSame('out_of_scope', $this->adapter()->facts($this->inspectionIntake($onRescue))->ineligibleReason);
    }

    public function test_a_response_after_the_submission_marks_it_answered(): void
    {
        $inspection = $this->missingOnUnit();
        $this->assertFalse($this->adapter()->facts($this->inspectionIntake($inspection))->alreadyAnswered);

        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $inspection->id, 'visibility' => 'member', 'body' => 'We have it.']);

        $facts = $this->adapter()->facts($this->inspectionIntake($inspection));
        $this->assertTrue($facts->alreadyAnswered);
        $this->assertFalse($facts->eligible());
    }

    public function test_facts_for_a_member_message_on_an_issue_thread(): void
    {
        $inspection = $this->missingOnUnit();
        $defect = ApparatusDefect::query()->sole();
        $messages = app(IssueMessageService::class);
        $message = $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $defect->id, 'body' => 'Any word on this?']);
        $intake = $this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $message->id);

        $facts = $this->adapter()->facts($intake);
        $this->assertTrue($facts->eligible());
        $this->assertSame('issue_message', $facts->kind);
        $this->assertSame('Any word on this?', $facts->memberText);
        $this->assertSame((int) $this->alpha->id, $facts->requesterUserId);
        $this->assertSame($defect->item, $facts->findings[0]->itemName);

        $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'Ordered.']);
        $this->assertTrue($this->adapter()->facts($intake)->alreadyAnswered);

        // Internal notes and staff messages are never answered.
        $internal = $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'internal', 'body' => 'Note.']);
        $this->assertSame('internal', $this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $internal->id))->ineligibleReason);
        $staff = $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'Hello.']);
        $this->assertSame('not_member_authored', $this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $staff->id))->ineligibleReason);
    }

    // ------------------------------------------------------------ writeReply

    public function test_write_reply_appends_one_assistant_message_idempotently_and_changes_nothing_else(): void
    {
        $inspection = $this->missingOnUnit();
        $intake = $this->inspectionIntake($inspection);
        $facts = $this->adapter()->facts($intake);
        $before = $this->snapshot();
        Notification::fake();

        $first = $this->adapter()->writeReply($intake, $facts, 'Your report was received.');
        $second = $this->adapter()->writeReply($intake, $facts, 'Your report was received.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('apparatus_issue_message', $first->type);
        $message = ApparatusIssueMessage::query()->where('author_context', 'assistant')->sole();
        $this->assertSame((int) $message->id, $first->id);
        $this->assertNull($message->author_user_id);
        $this->assertSame('member', $message->visibility);
        $this->assertSame((int) $inspection->id, (int) $message->apparatus_inspection_id);
        $this->assertNull($message->apparatus_defect_id);
        $this->assertSame('Your report was received.', $message->body);
        $this->assertSame($before, $this->snapshot(), 'The receipt must not touch a defect, inspection, unit or review record.');
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertTrue($this->adapter()->facts($intake)->alreadyAnswered, 'A second pass inside the engine skips instead of posting again.');
    }

    public function test_the_receipt_reads_ai_response_everywhere_and_is_not_a_staff_reply(): void
    {
        $inspection = $this->missingOnUnit();
        $intake = $this->inspectionIntake($inspection);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Your report was received.');
        $message = ApparatusIssueMessage::query()->sole();

        $this->assertTrue($message->isFromAssistant());
        $this->assertFalse($message->isFromStaff());
        $this->assertFalse($message->isFromLogistics());
        $this->assertSame(0, ApparatusIssueMessage::query()->fromStaff()->count());

        // The member's projection and page.
        $detail = app(EmployeeInspectionHistoryService::class)->detail($inspection);
        $conversation = app(MemberInspectionConversation::class)->project($this->alpha, $inspection, $detail);
        $row = $conversation['inspection']['messages'][0];
        $this->assertSame('AI Response', $row['author']);
        $this->assertFalse($row['own']);
        $this->assertFalse($row['from_logistics']);
        $this->assertNull($conversation['clarification']);
        $this->assertNull($conversation['rejection_reason']);
        $this->actAs($this->alpha);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->assertSee('AI Response')->assertSee('Your report was received.');

        // The Logistics inspection page and timeline.
        $workspace = app(InspectionWorkspace::class)->build($inspection, $this->reviewer);
        $this->assertSame('AI Response', collect($workspace['general_thread'])->firstWhere('id', (int) $message->id)['author']);
        $this->assertContains('AI Response', collect($workspace['timeline'] ?? [])->pluck('actor')->all());

        // The staff-attention dashboard feed and the crew-wide issue history never carry it.
        $this->assertSame([], collect(DailyCheckoutNotesFeed::build()['rows'])->filter(fn (array $r): bool => str_contains($r['author'], 'AI') || $r['excerpt'] === 'Your report was received.')->all());
        $latest = collect(app(DefectContinuityService::class)->openFindings($this->unit->fresh(), app(\App\Services\DailyCheckoutChecklistResolver::class)->resolve($this->unit->fresh())['checklist']))->first();
        $this->assertNull($latest['latest_member_update']);
    }

    public function test_a_reply_to_an_issue_message_goes_on_that_issue_thread(): void
    {
        $inspection = $this->missingOnUnit();
        $defect = ApparatusDefect::query()->sole();
        $member = app(IssueMessageService::class)->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $defect->id, 'body' => 'Any word?']);
        $intake = $this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $member->id);
        $before = $this->snapshot();

        $record = $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Your message was received.');

        $reply = ApparatusIssueMessage::query()->findOrFail($record->id);
        $this->assertSame((int) $defect->id, (int) $reply->apparatus_defect_id);
        $this->assertSame((int) $inspection->id, (int) $reply->apparatus_inspection_id);
        $this->assertTrue($reply->isFromAssistant());
        $this->assertSame($before, $this->snapshot());

        $detail = app(EmployeeInspectionHistoryService::class)->detail($inspection);
        $thread = app(MemberInspectionConversation::class)->project($this->alpha, $inspection, $detail)['issues'][$defect->id]['messages'];
        $this->assertSame('AI Response', end($thread)['author']);
        $this->assertTrue($this->adapter()->facts($intake)->alreadyAnswered);
        $this->assertSame($record->id, $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Your message was received.')->id);
    }

    public function test_the_member_url_points_at_the_inspection_messages(): void
    {
        $inspection = $this->missingOnUnit();
        $url = $this->adapter()->memberUrl($this->inspectionIntake($inspection));

        $this->assertNotNull($url);
        $this->assertStringContainsString('/my-vehicle-inspections/'.$inspection->id, (string) $url);
        $this->assertStringEndsWith('#messages', (string) $url);
    }

    // ------------------------------------------------------------ existing behavior

    public function test_present_on_the_next_checkout_is_still_only_an_observation(): void
    {
        $this->switchOn();
        $this->missingOnUnit();
        $this->actAs($this->alpha);

        $second = ApparatusInspection::query()->findOrFail($this->submit($this->payload(null, 1040, 402.4))->assertCreated()->json('id'));

        $defect = ApparatusDefect::query()->sole();
        $this->assertFalse((bool) $defect->resolved, 'A Present answer must never close the issue.');
        $this->assertSame('reported', $defect->action_status);
        $this->assertSame('appears_corrected', ApparatusDefectObservation::query()->where('apparatus_inspection_id', $second->id)->value('observation'));
        $this->assertSame('no_findings', $this->adapter()->facts($this->inspectionIntake($second))->ineligibleReason);
        // The receipt for this clean follow-up checkout is still an intake row the engine will skip, never a reply.
        $this->assertSame(2, AiIntakeEvent::query()->where('source_type', VehicleAdapter::SOURCE_INSPECTION)->count());
    }

    // ------------------------------------------------------------ backlog

    public function test_backlog_returns_only_unattended_open_checkouts_in_keyset_order(): void
    {
        $a = $this->missingOnUnit();

        // Attended: Logistics already wrote to the member.
        $frontPayload = $this->payload($this->frontline, 5020, 901.0);
        [$fc, $fi] = $this->pick($frontPayload, 'engine', false);
        $b = $this->checkout([[$fc, $fi, 'Missing', []]], $this->frontline);
        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $b->id, 'visibility' => 'member', 'body' => 'We have it.']);

        // Clean, test-excluded and medical-only checkouts are never offered.
        $this->actAs($this->alpha);
        $this->submit($this->payload(null, 1040, 402.4))->assertCreated();
        $payload = $this->payload(null, 1060, 403.6);
        [$mc, $mi] = $this->pick($payload, 'engine2', true);
        $this->checkout([[$mc, $mi, 'Missing', []]], null, 1060, 403.6);

        // Unattended on another Engine: offered.
        $second = Apparatus::query()->create([
            'station_id' => $this->frontline->station_id, 'vin' => 'VIN-404', 'assignment' => 'Station 3', 'unit_id' => 'E4', 'designation' => 'E4',
            'name' => 'Engine 4', 'type' => 'Engine', 'vehicle_number' => 'V-404', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
            'current_engine_hours' => 500, 'current_miles' => 3000,
        ]);
        [$sc, $si] = $this->pick($this->payload($second, 3020, 501.0), 'engine', false);
        $f = $this->checkout([[$sc, $si, 'Missing', []]], $second, 3020, 501.0);

        $ids = fn (array $candidates): array => array_map(fn ($candidate): int => $candidate->sourceId, $candidates);
        $this->assertSame([(int) $a->id, (int) $f->id], $ids($this->adapter()->backlogCandidates('apparatus_inspection', 0, 10)));
        $this->assertSame([(int) $a->id], $ids($this->adapter()->backlogCandidates('apparatus_inspection', 0, 1)));
        $this->assertSame([(int) $f->id], $ids($this->adapter()->backlogCandidates('apparatus_inspection', (int) $a->id, 10)));
        $this->assertSame([], $this->adapter()->backlogCandidates('apparatus_inspection', (int) $f->id, 10));
        $this->assertSame([], $this->adapter()->backlogCandidates('apparatus_issue_message', 0, 10));
        $this->assertSame('received', $this->adapter()->backlogCandidates('apparatus_inspection', 0, 1)[0]->eventKey);

        // Once the receipt is sent it drops out of the backlog.
        $intake = $this->inspectionIntake($a);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Your report was received.');
        $this->assertSame([(int) $f->id], $ids($this->adapter()->backlogCandidates('apparatus_inspection', 0, 10)));
    }
}
