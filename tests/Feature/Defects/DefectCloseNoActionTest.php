<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Filament\Support\DefectCorrectiveActions;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectEventNarrative;
use App\Support\Fleet\DefectFollowUpState;
use App\Support\Fleet\DefectNoActionClosure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/**
 * "Close — No Action Required" (stored as `dismissed`): the reason rules, the guards that keep a real
 * or dangerous defect from leaving the board through it, the always-on notification, and the record it leaves.
 * The Captain 5 door is covered in tests/Feature/Captain5/Captain5CloseNoActionTest.php.
 */
final class DefectCloseNoActionTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private const REASON = 'Duplicate of the report from Oct 20.';

    private DefectCorrectiveActionService $service;

    private Apparatus $rig;

    private User $reporter;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->service = app(DefectCorrectiveActionService::class);
        $this->rig = $this->rig();
        $this->reporter = $this->member('Riley Reporter');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter);
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->reporter);
    }

    private function events(string $type): int
    {
        return ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', $type)->count();
    }

    private function assertStillOpenWithNoEvent(): void
    {
        $this->assertSame('reported', $this->defect->fresh()->action_status);
        $this->assertFalse($this->defect->fresh()->resolved);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->count());
    }

    // ------------------------------------------------------------------ labels

    public function test_the_department_wording_is_exact(): void
    {
        $this->assertSame('Close — No Action Required', DefectNoActionClosure::ACTION_LABEL);
        $this->assertSame('Closed — no action required', DefectNoActionClosure::STATUS_LABEL);
        $this->assertSame('Closed — no action required', DefectActionStatus::label('dismissed'));
        $this->assertSame('Resolved', DefectActionStatus::label('resolved'));
        $this->assertSame('Why is no corrective action necessary?', DefectNoActionClosure::REASON_LABEL);
        $this->assertSame(
            'Use this only when the report was entered incorrectly, is a duplicate, or no corrective action is necessary. Closing it does not mean the equipment was repaired or replaced. The reason and original report remain in the apparatus history.',
            DefectNoActionClosure::DIALOG_TEXT,
        );
        // Stored values are unchanged.
        $this->assertSame('dismissed', DefectActionStatus::DISMISSED);
    }

    // ------------------------------------------------------------------ reason

    /** @return array<string, array{0: string}> */
    public static function badReasons(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['      '],
            'n/a' => ['n/a'],
            'na' => ['na'],
            'none' => ['none'],
            'test' => ['test'],
            'dot' => ['.'],
            'dash' => ['-'],
            'too short' => ['Duplicate'],
            'one char' => ['x'],
            'padded short' => ['   dup   '],
            'restates the button' => ['No action required'],
            'one repeated word' => ['none none none none'],
            'one repeated letter' => ['aaaaaaaaaaaaaaaaaaaa'],
            'punctuation only' => ['...............'],
            'only markup' => ['<div></div> <span></span> <p>&nbsp;</p>'],
            'markup around a placeholder' => ['<b>none</b> <i>none</i> <u>none</u> none'],
            'the prompt itself' => ['Why is no corrective action necessary?'],
            'the prompt in other case and spacing' => ['  WHY IS   NO corrective ACTION necessary '],
            'the action label' => ['Close — No Action Required'],
            'the action label lower case' => ['close no action required!!'],
            'two distinct words repeated' => ['wrong rig wrong rig wrong rig'],
            'a short phrase with zero-width padding' => ["ab cd\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}"],
            'repeated syllables' => ['xxxx yyyy zzzz aaaa'],
        ];
    }

    #[DataProvider('badReasons')]
    public function test_the_service_refuses_an_empty_or_placeholder_reason(string $reason): void
    {
        try {
            $this->service->dismiss($this->defect->id, $this->admin, ['reason' => $reason]);
            $this->fail('A placeholder reason must be refused by the service, not just the form.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
            $this->assertStringContainsString('at least 15 characters', $exception->errors()['reason'][0]);
        }
        $this->assertStillOpenWithNoEvent();
    }

    public function test_a_real_reason_is_trimmed_and_stored(): void
    {
        $defect = $this->service->dismiss($this->defect->id, $this->admin, ['reason' => "  Entered on the wrong rig by mistake.  \n"]);

        $this->assertSame('dismissed', $defect->action_status);
        $this->assertSame('Entered on the wrong rig by mistake.', $defect->resolution_notes);
        $this->assertSame('Entered on the wrong rig by mistake.', ApparatusDefectEvent::query()->sole()->details['reason']);
    }

    // ------------------------------------------------------------------ permissions

    public function test_a_member_cannot_close_an_issue_with_no_action_whatever_they_type(): void
    {
        $member = $this->member('Sam Member');

        foreach ([self::REASON, 'x'] as $reason) {
            try {
                $this->service->dismiss($this->defect->id, $member, ['reason' => $reason]);
                $this->fail('A member must not close issues.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        // The reporter of the issue is still only a member.
        $this->expectException(AuthorizationException::class);
        $this->service->dismiss($this->defect->id, $this->reporter, ['reason' => self::REASON]);
    }

    public function test_the_members_page_has_no_close_action_to_offer(): void
    {
        // The only action offered on a closed card is Reopen; on an open card Dismiss is a reviewer action.
        $this->assertSame([DefectCorrectiveActions::REOPEN], DefectCorrectiveActions::applicable($this->defect->forceFill(['action_status' => 'dismissed']), false));
        $this->assertFalse(DefectCorrectiveActions::canManage($this->reporter));
        $this->assertTrue(DefectCorrectiveActions::canManage($this->admin));
    }

    // ------------------------------------------------------------------ guards

    public function test_a_significant_damage_issue_cannot_be_closed_with_no_action(): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();

        try {
            $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
            $this->fail('Significant damage must not leave the board as "no action required".');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['defect'][0];
            $this->assertStringContainsString('significant damage', $message);
            $this->assertStringContainsString('affects whether the unit can respond', $message);
            // Severity has an audited update path, so the advice points to it.
            $this->assertStringContainsString('If the report was entered incorrectly, change the severity with Correct severity', $message);
            $this->assertStringContainsString('recorded in the history', $message);
            $this->assertStringNotContainsString('Classify impact', $message);
            $this->assertStringNotContainsString('ask Fleet', $message);
        }
        $this->assertSame('reported', $this->defect->fresh()->action_status);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function blockingImpacts(): array
    {
        return ['out of service' => ['out_of_service', 'out of service'], 'needs repair' => ['needs_repair', 'needing repair']];
    }

    #[DataProvider('blockingImpacts')]
    public function test_an_issue_that_affects_readiness_is_refused_until_the_impact_is_corrected_through_the_audited_action(string $impact, string $phrase): void
    {
        $this->defect->forceFill(['operational_impact' => $impact])->save();

        try {
            $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
            $this->fail('An issue marked '.$phrase.' must not be closed with no action required.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['defect'][0];
            $this->assertStringContainsString($phrase, $message);
            $this->assertStringContainsString('affects whether the unit can respond', $message);
            $this->assertStringContainsString('Classify impact', $message);
            $this->assertStringContainsString('recorded in the history', $message);
        }
        $this->assertSame('reported', $this->defect->fresh()->action_status);

        // The audited path: correct the impact (recorded), then the close is allowed.
        $this->service->classifyImpact($this->defect->id, $this->admin, ['operational_impact' => 'non_blocking', 'internal_note' => 'Entered against the wrong rig.']);
        $closed = $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);

        $this->assertSame('dismissed', $closed->action_status);
        $this->assertSame(1, $this->events('impact_classified'));
        $this->assertSame(1, $this->events('dismissed'));
    }

    /** @return array<string, array{0: string}> */
    public static function arrangedWork(): array
    {
        return ['repair scheduled' => ['repair_scheduled'], 'replacement available' => ['replacement_available']];
    }

    #[DataProvider('arrangedWork')]
    public function test_an_issue_with_repair_or_replacement_already_arranged_cannot_be_closed_with_no_action(string $status): void
    {
        $this->service->updateAction($this->defect->id, $this->admin, ['action_status' => $status]);

        try {
            $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
            $this->fail('Arranged work means action is required.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already arranged', $exception->errors()['defect'][0]);
            $this->assertStringContainsString('ask Fleet to correct the report', $exception->errors()['defect'][0]);
        }
        $this->assertSame($status, $this->defect->fresh()->action_status);
        $this->assertSame(0, $this->events('dismissed'));
    }

    public function test_an_order_on_record_blocks_the_close_even_while_the_status_is_only_action_pending(): void
    {
        $this->service->updateAction($this->defect->id, $this->admin, [
            'action_status' => 'action_pending',
            'details' => ['parts_ordered' => 'Battery pack', 'vendor_reference' => 'PO-4471'],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already arranged');
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
    }

    public function test_an_open_service_ticket_blocks_the_close_but_a_cancelled_one_does_not(): void
    {
        $this->allowApparatusUpdate($this->admin);
        $linked = $this->service->linkTicket($this->defect->id, $this->admin, []);
        $number = DB::table('apparatus_service_tickets')->where('id', $linked->service_ticket_id)->value('ticket_number');

        try {
            $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
            $this->fail('An open ticket means work is in motion.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString("A service ticket ({$number}) is still open", $exception->errors()['defect'][0]);
            $this->assertStringContainsString('Complete or cancel the ticket first', $exception->errors()['defect'][0]);
        }
        $this->assertSame(0, $this->events('dismissed'));

        // Cancelled is no longer open work and nothing was repaired: the reviewer's reason decides.
        DB::table('apparatus_service_tickets')->where('id', $linked->service_ticket_id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $closed = $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
        $this->assertSame('dismissed', $closed->action_status);
    }

    public function test_a_routine_non_blocking_issue_can_be_closed_and_a_repeat_close_is_a_no_op(): void
    {
        $this->service->classifyImpact($this->defect->id, $this->admin, ['operational_impact' => 'non_blocking']);

        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
        $again = $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);

        $this->assertSame('dismissed', $again->action_status);
        $this->assertSame(1, $this->events('dismissed'));
    }

    // ------------------------------------------------------------------ history

    public function test_the_authoritative_history_records_actor_time_reason_and_the_status_before(): void
    {
        $this->service->acknowledge($this->defect->id, $this->admin);
        $this->travelTo(now()->addMinutes(5));
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON, 'internal_note' => 'Checked the Oct 20 entry.']);

        $event = ApparatusDefectEvent::query()->where('event_type', 'dismissed')->sole();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame('acknowledged', $event->from_status);
        $this->assertSame('dismissed', $event->to_status);
        $this->assertSame(self::REASON, $event->details['reason']);
        $this->assertSame('acknowledged', $event->details['previous_action_status']);
        $this->assertNotNull($event->created_at);
        $this->assertTrue($event->created_at->greaterThan(ApparatusDefectEvent::query()->where('event_type', 'acknowledged')->sole()->created_at));

        $defect = $this->defect->fresh();
        $this->assertSame($this->admin->id, $defect->resolved_by_user_id);
        $this->assertNotNull($defect->resolved_at);
        $this->assertSame(self::REASON, $defect->resolution_notes);
        $this->assertNull($defect->resolution_type, 'Nothing was repaired, so there is no resolution type.');

        $lines = DefectEventNarrative::lines($event);
        $this->assertContains('Reason: '.self::REASON, $lines);
        $this->assertContains('Status before it was closed: Acknowledged by Logistics', $lines);
        $this->assertContains('Nothing was repaired or replaced.', $lines);
        $this->assertSame('Closed — no action required', DefectEventNarrative::label('dismissed'));
    }

    // ------------------------------------------------------------------ not resolved

    public function test_a_closed_no_action_issue_is_closed_but_never_reads_as_resolved(): void
    {
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
        $fixed = $this->openDefect($this->rig, $this->inspection, $this->reporter, 'Hose nozzle');
        $this->service->resolve($fixed->id, $this->admin, ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'New nozzle issued.']);

        $dismissed = $this->defect->fresh();
        $this->assertTrue($dismissed->resolved, 'resolved = true still means "no longer open".');
        $this->assertTrue($dismissed->isClosedNoAction());
        $this->assertFalse($fixed->fresh()->isClosedNoAction());
        $this->assertSame(['dismissed'], ApparatusDefect::query()->closedNoAction()->pluck('action_status')->all());
        $this->assertSame(['resolved'], ApparatusDefect::query()->fixed()->pluck('action_status')->all());
        $this->assertSame('closed_no_action', DefectActionStatus::closureKind('dismissed'));
        $this->assertSame('resolved', DefectActionStatus::closureKind('resolved'));
        $this->assertNull(DefectActionStatus::closureKind('reported'));

        $dismissed->load('serviceTicket');
        $this->assertSame(DefectFollowUpState::CLOSED_NO_ACTION, DefectFollowUpState::for($dismissed));
        $this->assertSame(DefectFollowUpState::RESOLVED, DefectFollowUpState::for($fixed->fresh()));
        $this->assertSame('Closed — no action required', DefectFollowUpState::label(DefectFollowUpState::CLOSED_NO_ACTION));
        $this->assertSame([$dismissed->id], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::CLOSED_NO_ACTION)->pluck('id')->all());
        $this->assertSame([$fixed->id], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::RESOLVED)->pluck('id')->all(), 'The Resolved tab holds repairs only.');
    }

    public function test_a_cancelled_ticket_is_shown_differently_from_a_completed_one(): void
    {
        $this->allowApparatusUpdate($this->admin);
        $linked = $this->service->linkTicket($this->defect->id, $this->admin, []);
        $ticketId = $linked->service_ticket_id;

        DB::table('apparatus_service_tickets')->where('id', $ticketId)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->assertSame(DefectFollowUpState::TICKET_CANCELLED, DefectFollowUpState::for($this->defect->fresh()->load('serviceTicket')));
        $this->assertSame('Service ticket cancelled', DefectFollowUpState::label(DefectFollowUpState::TICKET_CANCELLED));
        $this->assertSame([$this->defect->id], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::TICKET_CANCELLED)->pluck('id')->all());
        $this->assertSame([], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::TICKET_CLOSED)->pluck('id')->all());

        DB::table('apparatus_service_tickets')->where('id', $ticketId)->update(['status' => 'completed', 'cancelled_at' => null, 'completed_at' => now()]);
        $this->assertSame(DefectFollowUpState::TICKET_CLOSED, DefectFollowUpState::for($this->defect->fresh()->load('serviceTicket')));
        $this->assertSame('Service ticket completed', DefectFollowUpState::label(DefectFollowUpState::TICKET_CLOSED));
        $this->assertSame([$this->defect->id], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::TICKET_CLOSED)->pluck('id')->all());
        $this->assertSame([], DefectFollowUpState::applyFilter(ApparatusDefect::query(), DefectFollowUpState::TICKET_CANCELLED)->pluck('id')->all());
    }

    // ------------------------------------------------------------------ notification

    public function test_the_reporter_and_every_participant_are_told_with_the_reason_and_nobody_else(): void
    {
        $confirmer = $this->member('Casey Confirmer');
        $this->participant($this->defect, $confirmer, 'confirmed', $this->inspection);
        $replier = $this->member('Robin Replier');
        $this->participant($this->defect, $replier, 'replied', $this->inspection);
        $adminAlsoFollowing = $this->reviewer('admin', 'Second Admin');
        $this->participant($this->defect, $adminAlsoFollowing, 'assigned', $this->inspection);
        $bystander = $this->member('Bea Bystander');
        // Lost eligibility since they confirmed it: no account access, no alert.
        $gone = $this->member('Gone Member', active: false);
        $this->participant($this->defect, $gone, 'confirmed', $this->inspection);

        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);

        foreach ([$this->reporter, $confirmer, $replier, $adminAlsoFollowing] as $person) {
            Notification::assertSentToTimes($person, InspectionEventNotification::class, 1);
            Notification::assertSentTo($person, InspectionEventNotification::class, function (InspectionEventNotification $n): bool {
                return str_contains($n->title, 'issue closed, no action required')
                    && str_contains($n->body, 'was closed with no action required')
                    && str_contains($n->body, 'Reason: Duplicate of the report from Oct 20')
                    && str_contains($n->body, 'It was not repaired or replaced.')
                    && ! str_contains($n->body, 'resolved');
            });
        }
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
        Notification::assertNotSentTo($bystander, InspectionEventNotification::class);
        Notification::assertNotSentTo($gone, InspectionEventNotification::class);

        $event = ApparatusDefectEvent::query()->where('event_type', 'dismissed')->sole();
        $key = 'defect:'.$this->defect->id.':closed:'.$event->id;
        $recipients = DB::table('inspection_notification_deliveries')->where('event_key', $key)->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $this->assertSame(collect([$this->reporter, $confirmer, $replier, $adminAlsoFollowing])->map(fn (User $u): int => (int) $u->id)->sort()->values()->all(), $recipients);
    }

    public function test_the_notification_needs_no_typed_message_and_a_typed_one_is_added_not_duplicated(): void
    {
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON, 'member_message' => 'Thanks for flagging it anyway.']);

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->body, 'Reason: Duplicate of the report from Oct 20')
            && str_contains($n->body, 'Thanks for flagging it anyway.'));
    }

    public function test_a_retry_with_the_same_key_sends_nothing_twice_and_a_stuck_alert_can_be_redelivered(): void
    {
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON], 'form-key-1');
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON], 'form-key-1');

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        $event = ApparatusDefectEvent::query()->where('event_type', 'dismissed')->sole();
        $key = 'defect:'.$this->defect->id.':closed:'.$event->id;

        $this->assertTrue(app(InspectionNotificationDispatcher::class)->redeliver($key, 'issue_member_update', $this->reporter->id));
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 2);
    }

    // ------------------------------------------------------------------ reopen

    public function test_a_closed_no_action_issue_can_be_reopened_and_the_reopening_is_recorded(): void
    {
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
        $this->assertSame([DefectCorrectiveActions::REOPEN], DefectCorrectiveActions::applicable($this->defect->fresh(), false));

        $reopened = $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'It was a different battery, so this is real.']);

        $this->assertSame('reported', $reopened->action_status);
        $this->assertFalse($reopened->resolved);
        $this->assertNull($reopened->resolution_notes);
        $event = ApparatusDefectEvent::query()->where('event_type', 'reopened')->sole();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame('dismissed', $event->from_status);
        $this->assertSame('It was a different battery, so this is real.', $event->details['reason']);
        $this->assertSame(self::REASON, $event->details['previous_resolution_notes'], 'The original close reason survives the reopening.');
        $this->assertContains('Reason: It was a different battery, so this is real.', DefectEventNarrative::lines($event));
        $this->assertSame(1, $this->events('dismissed'), 'The original closure stays in the history.');
    }

    // ------------------------------------------------------------------ resolve options

    public function test_report_was_corrected_is_no_longer_a_new_resolution_but_old_rows_still_render(): void
    {
        $this->assertArrayNotHasKey('report_corrected', DefectCorrectiveActions::RESOLUTION_OPTIONS);
        $this->assertArrayHasKey('report_corrected', DefectCorrectiveActions::RESOLUTION_LABELS, 'Old rows keep their label.');

        try {
            $this->service->resolve($this->defect->id, $this->admin, ['resolution_type' => 'report_corrected', 'resolution_notes' => 'The checklist was wrong.']);
            $this->fail('"The report was wrong" is now Close — No Action Required.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Close — No Action Required', $exception->errors()['resolution_type'][0]);
        }
        $this->assertSame(0, ApparatusDefectEvent::query()->count());

        // A row resolved before this change still resolves its notification wording.
        $old = $this->openDefect($this->rig, $this->inspection, $this->reporter, 'Old closed item');
        $old->forceFill(['action_status' => 'resolved', 'resolution_type' => 'report_corrected', 'resolved_by_user_id' => $this->admin->id])->save();
        $this->assertTrue($old->fresh()->resolved);
        $this->assertFalse($old->fresh()->isClosedNoAction());
    }
}
