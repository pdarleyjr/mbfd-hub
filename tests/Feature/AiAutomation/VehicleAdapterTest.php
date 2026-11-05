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

    /** Makes the open issue one a later Present answer cannot close: damaged equipment waits for the owner to verify. */
    private function needsVerification(): void
    {
        ApparatusDefect::query()->whereKey(ApparatusDefect::query()->sole()->id)->update(['issue_type' => 'damaged']);
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

    public function test_a_live_clean_checkout_is_eligible_for_the_receipt_and_backlog_work_never_is(): void
    {
        $this->actAs($this->alpha);
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($this->payload())->assertCreated()->json('id'));

        $live = $this->adapter()->facts($this->inspectionIntake($inspection));
        $this->assertTrue($live->eligible());
        $this->assertSame([], $live->findings);
        $this->assertSame((int) $this->alpha->id, $live->requesterUserId);

        $backlog = $this->inspectionIntake($inspection);
        $backlog->forceFill(['origin' => AiIntakeEvent::ORIGIN_BACKLOG])->save();
        $facts = $this->adapter()->facts($backlog);
        $this->assertSame('no_findings', $facts->ineligibleReason);
        $this->assertFalse($facts->eligible());
        $this->assertSame([], $this->adapter()->backlogCandidates(VehicleAdapter::SOURCE_INSPECTION, 0, 50), 'a clean checkout is never a backlog candidate');
    }

    public function test_a_clean_checkout_already_answered_on_its_thread_is_not_answered_again(): void
    {
        $this->actAs($this->alpha);
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($this->payload())->assertCreated()->json('id'));
        $intake = $this->inspectionIntake($inspection);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Inspection received and recorded. No new issues reported.');

        $facts = $this->adapter()->facts($intake);
        $this->assertFalse($facts->eligible());
        $this->assertTrue($facts->alreadyAnswered);
    }

    public function test_a_missing_source_and_a_test_excluded_checkout_are_ineligible(): void
    {
        $inspection = $this->missingOnUnit();

        $gone = $this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_INSPECTION, 987654));
        $this->assertSame('source_missing', $gone->ineligibleReason);

        ApparatusInspection::query()->whereKey($inspection->id)->update(['test_excluded_at' => now()]);
        $this->assertSame('test_excluded', $this->adapter()->facts($this->inspectionIntake($inspection))->ineligibleReason);
    }

    // ------------------------------------------------------------ scope: Rescue and Captain 5 items are acknowledged too

    /** A Rescue unit on its own truck, checked out with the real Rescue checklist. */
    private function rescueUnit(string $number = 'R1', string $vehicle = 'V-R1'): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $this->unit->station_id, 'vin' => 'VIN-'.$number, 'assignment' => 'Station 2', 'unit_id' => $number, 'designation' => $number,
            'name' => 'Rescue '.$number, 'type' => 'Rescue', 'vehicle_number' => $vehicle, 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
            'current_engine_hours' => 100, 'current_miles' => 100,
        ]);
    }

    private function ladderUnit(string $number = 'L1', string $template = 'ladder1', string $vehicle = 'V-L1'): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $this->unit->station_id, 'vin' => 'VIN-'.$number, 'assignment' => 'Station 2', 'unit_id' => $number, 'designation' => $number,
            'name' => 'Ladder '.$number, 'type' => 'Ladder', 'vehicle_number' => $vehicle, 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => $template,
            'current_engine_hours' => 200, 'current_miles' => 300,
        ]);
    }

    /** Check out $apparatus with one plain (non-medical or medical) item Missing and return the facts of that checkout. */
    private function factsForOneMissing(Apparatus $apparatus, string $template, bool $medical, array $extra = []): \App\Services\AiAutomation\IntakeFacts
    {
        $payload = $this->payload($apparatus, (int) $apparatus->fresh()->current_miles + 20, (float) $apparatus->fresh()->current_engine_hours + 1.2);
        [$c, $i] = $this->pick($payload, $template, $medical);
        $inspection = $this->checkout([[$c, $i, 'Missing', $extra]], $apparatus);

        return $this->adapter()->facts($this->inspectionIntake($inspection));
    }

    public function test_medical_items_on_an_engine_checkout_are_now_acknowledged_with_the_checklist_type(): void
    {
        $payload = $this->payload();
        [$mc, $mi] = $this->pick($payload, 'engine2', true);
        [$nc, $ni] = $this->pick($payload, 'engine2', false);

        $medicalOnly = $this->checkout([[$mc, $mi, 'Missing', ['notes' => 'Not in the box.']]]);
        $facts = $this->adapter()->facts($this->inspectionIntake($medicalOnly));
        $this->assertTrue($facts->eligible(), 'A medical-only finding used to be out of scope; it now gets the simple acknowledgment.');
        $this->assertCount(1, $facts->findings);
        $this->assertSame('engine2', $facts->findings[0]->apparatusType);
        $medicalDefect = ApparatusDefect::query()->sole();
        $this->assertSame((int) $medicalDefect->id, $facts->findings[0]->defectId);
        $this->assertSame($medicalDefect->item, $facts->findings[0]->itemName);

        // Mixed Logistics and medical items: both are described, in one set of facts (one reply), each with its own defect.
        $mixed = $this->checkout([[$mc, $mi, 'Damaged', []], [$nc, $ni, 'Missing', ['notes' => 'Gone.']]]);
        $facts = $this->adapter()->facts($this->inspectionIntake($mixed));
        $this->assertTrue($facts->eligible());
        $this->assertCount(2, $facts->findings);
        $this->assertSame(
            ApparatusDefect::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            collect($facts->findings)->pluck('defectId')->sort()->values()->all(),
        );
        $this->assertSame(['engine2'], collect($facts->findings)->pluck('apparatusType')->unique()->values()->all());
    }

    public function test_rescue_checkouts_are_acknowledged_and_marked_rescue(): void
    {
        $rescue = $this->rescueUnit();

        $facts = $this->factsForOneMissing($rescue, 'rescue', false, ['notes' => 'Not on the truck.']);

        $this->assertTrue($facts->eligible(), 'Rescue findings used to be out of scope; they now get the simple acknowledgment.');
        $this->assertSame('inspection_findings', $facts->kind);
        $this->assertSame('rescue', $facts->findings[0]->apparatusType);
        $this->assertSame((int) ApparatusDefect::query()->sole()->id, $facts->findings[0]->defectId);
        $this->assertTrue($facts->findings[0]->isNew);
    }

    public function test_engine_and_ladder_checkouts_carry_their_checklist_types(): void
    {
        $engine = $this->factsForOneMissing($this->frontline, 'engine', false);
        $ladder1 = $this->factsForOneMissing($this->ladderUnit(), 'ladder1', false);
        $ladder3 = $this->factsForOneMissing($this->ladderUnit('L3', 'ladder3', 'V-L3'), 'ladder3', true);

        $this->assertSame('engine', $engine->findings[0]->apparatusType);
        $this->assertSame('ladder1', $ladder1->findings[0]->apparatusType);
        $this->assertSame('ladder3', $ladder3->findings[0]->apparatusType, 'A medical item on a ladder is acknowledged too.');
        $this->assertTrue($ladder3->eligible());
    }

    public function test_an_unmapped_unit_still_gets_its_acknowledgment_with_no_type(): void
    {
        $inspection = $this->missingOnUnit();
        DB::table('apparatuses')->where('id', $this->unit->id)->update(['daily_checkout_template' => 'not-a-template']);

        $facts = $this->adapter()->facts($this->inspectionIntake($inspection));

        $this->assertTrue($facts->eligible());
        $this->assertNull($facts->findings[0]->apparatusType, 'The classifier falls back to its safe default when the checklist type is unknown.');
    }

    public function test_scheduled_duties_are_not_equipment_and_stay_out_of_scope(): void
    {
        $inspection = $this->missingOnUnit();
        ApparatusDefect::query()->sole()->forceFill(['compartment_key' => 'scheduled_duties'])->saveQuietly();

        $this->assertSame('out_of_scope', $this->adapter()->facts($this->inspectionIntake($inspection))->ineligibleReason);
    }

    public function test_the_adapter_never_reads_or_writes_captain5_or_ems_tables(): void
    {
        $rescue = $this->rescueUnit();
        $rescuePayload = $this->payload($rescue, 120, 101.0);
        [$rc, $ri] = $this->pick($rescuePayload, 'rescue', false);
        $inspection = $this->checkout([[$rc, $ri, 'Missing', []]], $rescue);
        $intake = $this->inspectionIntake($inspection);
        $message = app(IssueMessageService::class)->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => ApparatusDefect::query()->sole()->id, 'body' => 'Any word?']);
        $messageIntake = $this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $message->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $adapter = $this->adapter();
        $adapter->facts($intake);
        $adapter->facts($messageIntake);
        $adapter->writeReply($intake, $adapter->facts($intake), 'Your report was received.');
        $adapter->writeReply($messageIntake, $adapter->facts($messageIntake), 'Your message was received.');
        $adapter->backlogCandidates('apparatus_inspection', 0, 10);
        $adapter->memberUrl($intake);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertNotSame('', $sql);
        $this->assertDoesNotMatchRegularExpression('/\b(captain5_\w+|ems_\w+|apparatus_inventory_\w+)\b/i', $sql, 'No Captain 5, EMS or inventory-correction table may be touched.');
        $this->assertSame(2, ApparatusIssueMessage::query()->where('author_context', 'assistant')->count());
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
        $this->assertSame('Support Services', $row['author']);
        $this->assertFalse($row['own']);
        $this->assertFalse($row['from_logistics']);
        $this->assertNull($conversation['clarification']);
        $this->assertNull($conversation['rejection_reason']);
        $this->actAs($this->alpha);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->assertSee('Support Services')->assertSee('Your report was received.');

        // The Logistics inspection page and timeline.
        $workspace = app(InspectionWorkspace::class)->build($inspection, $this->reviewer);
        $this->assertSame('Support Services (automated)', collect($workspace['general_thread'])->firstWhere('id', (int) $message->id)['author']);
        $this->assertContains('Support Services (automated)', collect($workspace['timeline'] ?? [])->pluck('actor')->all());

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
        $this->assertSame('Support Services', end($thread)['author']);
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

    public function test_present_on_the_next_checkout_is_only_an_observation_for_an_issue_that_needs_verification(): void
    {
        $this->switchOn();
        $this->missingOnUnit();
        $this->needsVerification();
        $this->actAs($this->alpha);

        $second = ApparatusInspection::query()->findOrFail($this->submit($this->payload(null, 1040, 402.4))->assertCreated()->json('id'));

        $defect = ApparatusDefect::query()->sole();
        $this->assertFalse((bool) $defect->resolved, 'A Present answer never closes damaged equipment: the owner verifies it.');
        $this->assertSame('reported', $defect->action_status);
        $this->assertSame('appears_corrected', ApparatusDefectObservation::query()->where('apparatus_inspection_id', $second->id)->value('observation'));
        // A live follow-up checkout with no new and no confirmed-again finding is a clean checkout: it is owed only the one-line receipt.
        $facts = $this->adapter()->facts($this->inspectionIntake($second));
        $this->assertTrue($facts->eligible());
        $this->assertSame([], $facts->findings);
        $this->assertSame(2, AiIntakeEvent::query()->where('source_type', VehicleAdapter::SOURCE_INSPECTION)->count());
    }

    public function test_found_present_on_a_later_checkout_gets_only_the_clean_receipt_and_leaves_an_unverified_issue_alone(): void
    {
        $payload = $this->payload();
        [$ac, $ai] = $this->pick($payload, 'engine2', false);
        $a = $this->checkout([[$ac, $ai, 'Missing', ['notes' => 'Gone.']]]);
        $defect = ApparatusDefect::query()->sole();
        $this->needsVerification();
        $official = fn (): array => $defect->fresh()->only(['resolved', 'resolved_at', 'action_status', 'status', 'acknowledged_at', 'acknowledged_by_user_id', 'severity']);
        $before = $official();

        // The next checkout finds it Present (no finding): no reply is owed and the defect keeps every official field.
        $this->actAs($this->alpha);
        $present = ApparatusInspection::query()->findOrFail($this->submit($this->payload(null, 1040, 402.4))->assertCreated()->json('id'));
        $facts = $this->adapter()->facts($this->inspectionIntake($present));
        $this->assertTrue($facts->eligible(), 'A live checkout with no new finding is owed only the clean receipt.');
        $this->assertSame([], $facts->findings);
        $this->assertSame($before, $official(), 'A Present answer is evidence for staff, not a closure.');
        $this->assertFalse((bool) $defect->fresh()->resolved);
        $this->assertSame('appears_corrected', ApparatusDefectObservation::query()->where('apparatus_inspection_id', $present->id)->value('observation'));

        // The first checkout is still owed its acknowledgment: the Present answer did not answer or close it.
        $this->assertTrue($this->adapter()->facts($this->inspectionIntake($a))->eligible());
    }

    public function test_a_routine_item_found_present_is_closed_by_that_checkout_and_the_first_report_is_not_answered_about_it(): void
    {
        $this->switchOn();
        $first = $this->missingOnUnit();
        $this->actAs($this->alpha);

        $present = ApparatusInspection::query()->findOrFail($this->submit($this->payload(null, 1040, 402.4))->assertCreated()->json('id'));

        $this->assertTrue((bool) ApparatusDefect::query()->sole()->resolved, 'A routine item found Present is resolved by that checkout.');
        $facts = $this->adapter()->facts($this->inspectionIntake($present));
        $this->assertTrue($facts->eligible(), 'The Present checkout is owed only the clean receipt.');
        $this->assertSame([], $facts->findings);
        $this->assertSame('issue_closed', $this->adapter()->facts($this->inspectionIntake($first))->ineligibleReason);
    }

    public function test_a_resolved_or_replaced_issue_is_not_answered_and_a_new_failure_is_a_new_issue(): void
    {
        $first = $this->missingOnUnit();
        $defect = ApparatusDefect::query()->sole();
        $intake = $this->inspectionIntake($first);
        $this->assertTrue($this->adapter()->facts($intake)->eligible());

        app(DefectCorrectiveActionService::class)->resolve($defect->id, $this->reviewer, ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'Replaced from stock.']);

        $this->assertSame('issue_closed', $this->adapter()->facts($intake)->ineligibleReason);
        $message = app(IssueMessageService::class)->post($this->alpha, ['inspection_id' => $first->id, 'defect_id' => $defect->id, 'body' => 'Thanks.']);
        $this->assertSame('issue_closed', $this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $message->id))->ineligibleReason);
        $this->assertSame([], $this->adapter()->backlogCandidates('apparatus_inspection', 0, 10));

        // The same item going missing again after the replacement is a fresh issue and is acknowledged again.
        $again = $this->missingOnUnit(['notes' => 'Gone again.']);
        $facts = $this->adapter()->facts($this->inspectionIntake($again));
        $this->assertTrue($facts->eligible());
        $this->assertTrue($facts->findings[0]->isNew);
        $this->assertNotSame((int) $defect->id, $facts->findings[0]->defectId);
    }

    public function test_a_replacement_available_status_counts_as_a_staff_response_for_a_repeat(): void
    {
        $this->missingOnUnit();
        app(DefectCorrectiveActionService::class)->markReplacementAvailable(ApparatusDefect::query()->sole()->id, $this->reviewer);
        $second = $this->missingOnUnit(['notes' => 'Still gone.']);

        $this->assertSame('repeat_only', $this->adapter()->facts($this->inspectionIntake($second))->ineligibleReason);
    }

    public function test_a_repeat_after_a_member_visible_staff_message_is_repeat_only_but_an_internal_note_is_not_a_response(): void
    {
        $this->missingOnUnit();
        $defect = ApparatusDefect::query()->sole();
        $messages = app(IssueMessageService::class);

        // An internal note is not a response to the member.
        $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'internal', 'body' => 'Check stock.']);
        $second = $this->missingOnUnit(['notes' => 'Still gone.']);
        $this->assertTrue($this->adapter()->facts($this->inspectionIntake($second))->eligible());

        // A member-visible staff message on the issue thread is.
        $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'We have a spare.']);
        $this->assertSame('repeat_only', $this->adapter()->facts($this->inspectionIntake($second))->ineligibleReason);
    }

    public function test_a_repeat_after_an_earlier_ai_reply_is_repeat_only(): void
    {
        $first = $this->missingOnUnit();
        $second = $this->missingOnUnit(['notes' => 'Still gone.']);
        $this->assertTrue($this->adapter()->facts($this->inspectionIntake($second))->eligible());

        $intake = $this->inspectionIntake($first);
        $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Your report was received.');
        $intake->forceFill(['state' => AiIntakeEvent::STATE_SENT])->save();

        $facts = $this->adapter()->facts($this->inspectionIntake($second));
        $this->assertFalse($facts->eligible());
        $this->assertSame('repeat_only', $facts->ineligibleReason, 'The member is not answered twice about the same issue.');
    }

    public function test_one_checkout_with_findings_of_every_priority_is_one_set_of_facts_and_one_reply(): void
    {
        $payload = $this->payload();
        $marks = [];
        foreach ($payload['compartments'] as $c => $compartment) {
            foreach ($compartment['items'] as $i => $item) {
                if (! array_key_exists('value', $item) && count($marks) < 3) {
                    $marks[] = [$c, $i, count($marks) === 0 ? 'Damaged' : 'Missing', count($marks) === 0 ? ['significant' => true, 'notes' => 'Cracked housing.'] : []];
                }
            }
        }
        $inspection = $this->checkout($marks);
        $intake = $this->inspectionIntake($inspection);

        $facts = $this->adapter()->facts($intake);

        $this->assertTrue($facts->eligible(), (string) $facts->ineligibleReason);
        $this->assertCount(3, $facts->findings);
        $this->assertSame(3, collect($facts->findings)->pluck('defectId')->unique()->count());
        $this->assertTrue($facts->findings[0]->significant);
        $this->assertFalse($facts->findings[2]->significant);
        $this->assertSame(['damaged', 'missing'], collect($facts->findings)->pluck('condition')->unique()->sort()->values()->all());

        $first = $this->adapter()->writeReply($intake, $facts, 'Your report was received.');
        $second = $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Your report was received.');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ApparatusIssueMessage::query()->where('author_context', 'assistant')->count());
    }

    public function test_an_offline_queued_checkout_replayed_records_exactly_one_intake_and_one_reply(): void
    {
        $this->switchOn();
        $this->actAs($this->alpha);
        $payload = $this->payload(null, 1020, 401.2);
        [$c, $i] = $this->pick($payload, 'engine2', false);
        $this->markItem($payload, $c, $i, 'Missing', ['notes' => 'Recorded offline.']);

        $created = $this->submit($payload)->assertCreated();
        $replay = $this->submit($payload);
        $this->assertContains($replay->getStatusCode(), [200, 201], 'The offline replay of the same client_submission_id is accepted as the same checkout.');
        $this->assertSame($created->json('id'), $replay->json('id'));
        $this->submit($payload);

        $this->assertSame(1, ApparatusInspection::query()->count());
        $this->assertSame(1, AiIntakeEvent::query()->where('source_type', VehicleAdapter::SOURCE_INSPECTION)->count());
        $intake = AiIntakeEvent::query()->sole();
        $facts = $this->adapter()->facts($intake);
        $this->assertTrue($facts->eligible());
        $one = $this->adapter()->writeReply($intake, $facts, 'Your report was received.');
        $two = $this->adapter()->writeReply($intake, $this->adapter()->facts($intake), 'Your report was received.');
        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, ApparatusIssueMessage::query()->where('author_context', 'assistant')->count());
    }

    public function test_a_unit_moved_to_a_reserve_truck_is_answered_with_the_units_own_checklist_type(): void
    {
        // The Engine 2 crew takes reserve truck V-909: the changeout moves the unit's identity (and checklist) onto it.
        app(\App\Services\Fleet\VehicleSwapService::class)->moveUnitForChangeout($this->unit->fresh(), $this->reserve->fresh());
        $reserve = $this->reserve->fresh();
        $this->assertSame('engine2', $reserve->daily_checkout_template->value);

        $payload = $this->payload($reserve, 8020, 1201.0);
        [$c, $i] = $this->pick($payload, 'engine2', false);
        $inspection = $this->checkout([[$c, $i, 'Missing', ['notes' => 'Not on the spare truck.']]], $reserve);
        $facts = $this->adapter()->facts($this->inspectionIntake($inspection));

        $this->assertTrue($facts->eligible());
        $this->assertSame('engine2', $facts->findings[0]->apparatusType);
        $this->assertSame((int) $reserve->id, (int) ApparatusDefect::query()->sole()->apparatus_id, 'The issue sits on the physical truck.');
    }

    public function test_a_rescue_moved_to_a_reserve_rescue_truck_is_still_a_rescue_acknowledgment(): void
    {
        $rescue = $this->rescueUnit('R44', 'V-R44');
        $spare = Apparatus::query()->create([
            'station_id' => $this->reserve->station_id, 'vin' => 'VIN-R1033', 'unit_id' => 'R-1033', 'designation' => 'R-1033', 'name' => 'RESCUE 1033',
            'slug' => 'r1033', 'type' => 'Rescue', 'vehicle_number' => 'V-1033', 'status' => 'Reserve',
            'daily_checkout_requirement' => 'reserve', 'daily_checkout_template' => 'rescue', 'current_engine_hours' => 300, 'current_miles' => 9000,
        ]);
        app(\App\Services\Fleet\VehicleSwapService::class)->moveUnitForChangeout($rescue->fresh(), $spare->fresh());
        $spare = $spare->fresh();

        $facts = $this->factsForOneMissing($spare, 'rescue', false, ['notes' => 'Not on the spare rescue.']);

        $this->assertTrue($facts->eligible());
        $this->assertSame('rescue', $facts->findings[0]->apparatusType);
    }

    public function test_member_follow_ups_on_rescue_and_medical_issue_threads_are_answered_once_per_conversation_key(): void
    {
        $payload = $this->payload();
        [$mc, $mi] = $this->pick($payload, 'engine2', true);
        $inspection = $this->checkout([[$mc, $mi, 'Missing', []]]);
        $defect = ApparatusDefect::query()->sole();
        $messages = app(IssueMessageService::class);

        $a = $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $defect->id, 'body' => 'Do we have a spare box?']);
        $b = $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $defect->id, 'body' => 'Following up on the box.']);
        $factsA = $this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $a->id));
        $factsB = $this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $b->id));

        $this->assertTrue($factsA->eligible());
        $this->assertSame('issue_message', $factsA->kind);
        $this->assertSame('engine2', $factsA->findings[0]->apparatusType);
        $this->assertSame((int) $defect->id, $factsA->findings[0]->defectId);
        $this->assertFalse($factsA->findings[0]->isNew);
        // Both follow-ups share one conversation key, which is what the engine's reply cooldown uses.
        $this->assertSame('apparatus_defect:'.$defect->id, $factsA->threadKey);
        $this->assertSame($factsA->threadKey, $factsB->threadKey);

        // Once the assistant (or staff) answers on that thread, later messages before it are answered, newer ones are not.
        $reply = $this->adapter()->writeReply($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $a->id), $factsA, 'Your message was received.');
        $this->assertGreaterThan((int) $b->id, $reply->id);
        $this->assertTrue($this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $a->id))->alreadyAnswered);
        $c = $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $defect->id, 'body' => 'Thanks, one more thing.']);
        $this->assertTrue($this->adapter()->facts($this->intakeFor(VehicleAdapter::SOURCE_ISSUE_MESSAGE, (int) $c->id))->eligible());
    }

    // ------------------------------------------------------------ backlog

    public function test_backlog_offers_historical_unresolved_rescue_and_medical_checkouts_but_not_attended_or_closed_ones(): void
    {
        $rescue = $this->rescueUnit();
        [$rc, $ri] = $this->pick($this->payload($rescue, 120, 101.0), 'rescue', false);
        $onRescue = $this->checkout([[$rc, $ri, 'Missing', []]], $rescue);
        [$mc, $mi] = $this->pick($this->payload(), 'engine2', true);
        $medical = $this->checkout([[$mc, $mi, 'Missing', []]]);

        $answered = $this->rescueUnit('R2', 'V-R2');
        [$ac, $ai] = $this->pick($this->payload($answered, 120, 101.0), 'rescue', false);
        $attended = $this->checkout([[$ac, $ai, 'Missing', []]], $answered);
        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $attended->id, 'visibility' => 'member', 'body' => 'We have it.']);

        $ids = fn (array $candidates): array => array_map(fn ($candidate): int => $candidate->sourceId, $candidates);
        $this->assertSame([(int) $onRescue->id, (int) $medical->id], $ids($this->adapter()->backlogCandidates('apparatus_inspection', 0, 10)));

        app(DefectCorrectiveActionService::class)->resolve(ApparatusDefect::query()->where('apparatus_id', $rescue->id)->sole()->id, $this->reviewer, ['resolution_type' => 'item_located', 'resolution_notes' => 'Found it.']);
        $this->assertSame([(int) $medical->id], $ids($this->adapter()->backlogCandidates('apparatus_inspection', 0, 10)));
    }

    public function test_backlog_returns_only_unattended_open_checkouts_in_keyset_order(): void
    {
        $a = $this->missingOnUnit();
        $this->needsVerification(); // the clean checkout below must not close it

        // Attended: Logistics already wrote to the member.
        $frontPayload = $this->payload($this->frontline, 5020, 901.0);
        [$fc, $fi] = $this->pick($frontPayload, 'engine', false);
        $b = $this->checkout([[$fc, $fi, 'Missing', []]], $this->frontline);
        app(IssueMessageService::class)->post($this->reviewer, ['inspection_id' => $b->id, 'visibility' => 'member', 'body' => 'We have it.']);

        // Clean and test-excluded checkouts are never offered.
        $this->actAs($this->alpha);
        $this->submit($this->payload(null, 1040, 402.4))->assertCreated();
        $payload = $this->payload(null, 1060, 403.6);
        [$mc, $mi] = $this->pick($payload, 'engine2', true);
        $test = $this->checkout([[$mc, $mi, 'Missing', []]], null, 1060, 403.6);
        ApparatusInspection::query()->whereKey($test->id)->update(['test_excluded_at' => now()]);

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
