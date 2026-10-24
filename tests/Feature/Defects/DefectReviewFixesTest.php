<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\DefectHistory;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Captain5\Captain5ActorContext;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Fleet\DefectEventNarrative;
use App\Support\Fleet\DefectNoActionClosure;
use App\Support\Fleet\DefectSeverityCorrection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/**
 * Fixes from the adversarial review of the defect state transitions: a repaired issue cannot be closed
 * as "nothing repaired", severity has an audited correction, a reopening is always announced, and the
 * reason rules reject text that merely looks like a reason.
 */
final class DefectReviewFixesTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private const REASON = 'Duplicate of the report from Oct 20.';

    private const SEVERITY_REASON = 'Entered as significant by mistake; the latch is only scuffed.';

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

    // ------------------------------------------------------------------ M1: a completed repair

    public function test_an_issue_whose_service_ticket_was_completed_cannot_be_closed_as_no_action(): void
    {
        $this->allowApparatusUpdate($this->admin);
        $linked = $this->service->linkTicket($this->defect->id, $this->admin, []);
        $number = DB::table('apparatus_service_tickets')->where('id', $linked->service_ticket_id)->value('ticket_number');
        DB::table('apparatus_service_tickets')->where('id', $linked->service_ticket_id)->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertNotNull(DefectCorrectiveActions::closeBlockedReason($this->defect->fresh()));
        $this->assertNotContains(DefectCorrectiveActions::DISMISS, DefectCorrectiveActions::applicable($this->defect->fresh(), false));
        try {
            $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
            $this->fail('A repair happened, so this issue is Resolved, not closed with nothing repaired.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['defect'][0];
            $this->assertStringContainsString("A service ticket ({$number}) was completed", $message);
            $this->assertStringContainsString('a repair was done', $message);
            $this->assertStringContainsString('Resolve it with Repair completed instead', $message);
        }
        $this->assertSame(0, $this->events('dismissed'));
        $this->assertSame('reported', $this->defect->fresh()->action_status);

        // The honest path is open.
        $resolved = $this->service->resolve($this->defect->id, $this->admin, ['resolution_type' => 'repair_completed', 'resolution_notes' => 'Repaired under the ticket.']);
        $this->assertSame('resolved', $resolved->action_status);
    }

    // ------------------------------------------------------------------ M2: severity correction

    public function test_a_reviewer_corrects_severity_with_a_recorded_reason_and_the_close_then_works(): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();
        $this->assertNotNull(DefectCorrectiveActions::closeBlockedReason($this->defect->fresh()));
        $this->assertContains(DefectCorrectiveActions::CORRECT_SEVERITY, DefectCorrectiveActions::applicable($this->defect->fresh(), false));
        $this->assertNotContains(DefectCorrectiveActions::DISMISS, DefectCorrectiveActions::applicable($this->defect->fresh(), false));

        $corrected = $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'routine', 'reason' => self::SEVERITY_REASON]);

        $this->assertSame('routine', $corrected->severity);
        $event = ApparatusDefectEvent::query()->where('event_type', 'severity_corrected')->sole();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertNotNull($event->created_at);
        $this->assertSame('significant', $event->details['from_severity']);
        $this->assertSame('routine', $event->details['to_severity']);
        $this->assertSame(self::SEVERITY_REASON, $event->details['reason']);
        $this->assertSame('reported', $event->from_status, 'the lifecycle status is untouched');
        $this->assertSame('reported', $corrected->action_status);

        $this->assertContains(DefectCorrectiveActions::DISMISS, DefectCorrectiveActions::applicable($this->defect->fresh(), false));
        $closed = $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
        $this->assertSame('dismissed', $closed->action_status);
    }

    public function test_severity_can_also_be_raised_and_a_repeat_or_same_value_is_a_no_op(): void
    {
        $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'significant', 'reason' => self::SEVERITY_REASON], 'sev-1');
        $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'significant', 'reason' => self::SEVERITY_REASON], 'sev-1');
        $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'significant', 'reason' => self::SEVERITY_REASON], 'sev-2');

        $this->assertSame('significant', $this->defect->fresh()->severity);
        $this->assertSame(1, $this->events('severity_corrected'));
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
    }

    public function test_only_reviewers_may_correct_severity(): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();
        foreach ([$this->reporter, $this->member('Sam Member')] as $member) {
            try {
                $this->service->correctSeverity($this->defect->id, $member, ['severity' => 'routine', 'reason' => self::SEVERITY_REASON]);
                $this->fail('A member must not correct severity.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('significant', $this->defect->fresh()->severity);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
        $this->assertNotContains('correct_severity', Captain5ActorContext::OPERATIONS, 'Captain 5 asks Logistics instead');
    }

    /** @return array<string, array{0: string}> */
    public static function badSeverityReasons(): array
    {
        return [
            'empty' => [''], 'short' => ['wrong'], 'placeholder' => ['n/a'], 'markup only' => ['<p></p> <b></b>'],
            'the prompt' => ['Why is the severity being changed?'], 'the action label' => ['Correct severity'],
            'two words repeated' => ['entered wrong entered wrong entered wrong'],
        ];
    }

    #[DataProvider('badSeverityReasons')]
    public function test_a_severity_correction_needs_a_real_reason(string $reason): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();

        try {
            $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'routine', 'reason' => $reason]);
            $this->fail('A placeholder reason must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertSame('significant', $this->defect->fresh()->severity);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->count());
    }

    public function test_only_routine_or_significant_and_only_open_issues_can_be_corrected(): void
    {
        foreach (['critical', '', 'Routine '] as $value) {
            try {
                $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => $value, 'reason' => self::SEVERITY_REASON]);
                $this->fail('Unknown severity "'.$value.'"');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('severity', $exception->errors());
            }
        }

        $this->defect->forceFill(['severity' => 'significant'])->save();
        $this->service->resolve($this->defect->id, $this->admin, ['resolution_type' => 'other', 'resolution_notes' => 'Handled it.']);
        $this->expectException(ValidationException::class);
        $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'routine', 'reason' => self::SEVERITY_REASON]);
    }

    public function test_the_reporter_and_participants_are_told_once_but_not_the_person_who_corrected_it(): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();
        $confirmer = $this->member('Casey Confirmer');
        $this->participant($this->defect, $confirmer, 'confirmed', $this->inspection);
        $bystander = $this->member('Bea Bystander');

        $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'routine', 'reason' => self::SEVERITY_REASON, 'internal_note' => 'Staff only.']);

        foreach ([$this->reporter, $confirmer] as $person) {
            Notification::assertSentToTimes($person, InspectionEventNotification::class, 1);
            Notification::assertSentTo($person, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->title, 'severity corrected')
                && str_contains($n->body, 'from significant damage to routine')
                && str_contains($n->body, 'Reason: Entered as significant by mistake')
                && ! str_contains($n->body, 'Staff only'));
        }
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
        Notification::assertNotSentTo($bystander, InspectionEventNotification::class);

        $event = ApparatusDefectEvent::query()->where('event_type', 'severity_corrected')->sole();
        $key = 'defect:'.$this->defect->id.':severity:'.$event->id;
        $this->assertSame(2, DB::table('inspection_notification_deliveries')->where('event_key', $key)->count());
        $this->assertTrue(app(InspectionNotificationDispatcher::class)->redeliver($key, 'issue_member_update', $this->reporter->id));
    }

    public function test_the_event_reads_in_the_history_narrative_and_on_the_members_page(): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();
        $this->service->correctSeverity($this->defect->id, $this->admin, ['severity' => 'routine', 'reason' => self::SEVERITY_REASON]);
        $event = ApparatusDefectEvent::query()->where('event_type', 'severity_corrected')->sole();

        $this->assertSame('Severity corrected', DefectEventNarrative::label('severity_corrected'));
        $this->assertSame(
            ['Severity changed from Significant damage to Routine.', 'Reason: '.self::SEVERITY_REASON],
            DefectEventNarrative::lines($event),
        );
        $row = DefectHistory::rows($this->defect, true)[0];
        $this->assertSame('Severity corrected', $row['title']);
        $this->assertSame($this->admin->name, $row['actor']);
        $this->assertContains('Reason: '.self::SEVERITY_REASON, $row['details']);

        $detail = app(EmployeeInspectionHistoryService::class)->detail($this->inspection);
        $followUp = collect([...$detail['findings'], ...$detail['unmatched_defects']])->pluck('follow_up')->first(fn ($f) => ($f['defect_id'] ?? null) === $this->defect->id);
        $this->assertSame('significant', $followUp['severity_correction']['from']);
        $this->assertSame('routine', $followUp['severity_correction']['to']);
        $this->assertSame(self::SEVERITY_REASON, $followUp['severity_correction']['reason']);
    }

    // ------------------------------------------------------------------ M3: reopening is announced

    public function test_reopening_a_no_action_closure_tells_the_reporter_and_participants_once_without_a_typed_message(): void
    {
        $confirmer = $this->member('Casey Confirmer');
        $this->participant($this->defect, $confirmer, 'confirmed', $this->inspection);
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
        Notification::fake();

        $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'It was a different battery, so this is real.']);

        foreach ([$this->reporter, $confirmer] as $person) {
            Notification::assertSentToTimes($person, InspectionEventNotification::class, 1);
            Notification::assertSentTo($person, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->title, 'issue reopened')
                && str_contains($n->body, 'was closed with no action required')
                && str_contains($n->body, 'reopened it')
                && ! str_contains($n->body, 'different battery'));
        }
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
        $event = ApparatusDefectEvent::query()->where('event_type', 'reopened')->sole();
        $this->assertSame(2, DB::table('inspection_notification_deliveries')->where('event_key', 'defect:'.$this->defect->id.':reopened:'.$event->id)->count());
    }

    public function test_reopening_a_resolved_issue_is_announced_with_the_typed_message_in_the_same_alert(): void
    {
        $this->service->resolve($this->defect->id, $this->admin, ['resolution_type' => 'repair_completed', 'resolution_notes' => 'Fixed.']);
        Notification::fake();

        $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'Failed again.', 'member_message' => 'Please recheck it tomorrow.']);

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->body, 'was marked resolved')
            && str_contains($n->body, 'Please recheck it tomorrow.'));
        $event = ApparatusDefectEvent::query()->where('event_type', 'reopened')->sole();
        $this->assertTrue(app(InspectionNotificationDispatcher::class)->redeliver('defect:'.$this->defect->id.':reopened:'.$event->id, 'issue_member_update', $this->reporter->id));
    }

    public function test_a_reopen_retry_with_the_same_key_does_not_alert_twice(): void
    {
        $this->service->dismiss($this->defect->id, $this->admin, ['reason' => self::REASON]);
        Notification::fake();

        $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'It was real.'], 'reopen-key');
        $this->service->reopen($this->defect->id, $this->admin, ['reason' => 'It was real.'], 'reopen-key');

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
    }

    // ------------------------------------------------------------------ reasons

    /** @return array<string, array{0: string, 1: bool}> */
    public static function reasons(): array
    {
        return [
            'real sentence' => ['Entered on the wrong unit by mistake.', true],
            'with markup around real words' => ['<b>Entered</b> on the <i>wrong</i> unit', true],
            'tags only' => ['<div></div> <span></span>', false],
            'tags hiding a placeholder' => ['<b>none</b> <i>none</i> <u>none</u> none', false],
            'prompt, other case' => ['WHY IS NO CORRECTIVE ACTION NECESSARY?', false],
            'action label' => ['close — no action required', false],
            'only two distinct words' => ['rig rig rig wrong wrong wrong', false],
            'zero width padding' => ["ab cd\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}\u{200B}", false],
            'three distinct words' => ['Duplicate of Oct 20 report', true],
        ];
    }

    #[DataProvider('reasons')]
    public function test_reason_rules(string $reason, bool $acceptable): void
    {
        $this->assertSame($acceptable, DefectNoActionClosure::reasonProblem($reason) === null, $reason);
    }

    public function test_markup_is_not_counted_toward_length(): void
    {
        $this->assertSame('Entered on the wrong unit', DefectNoActionClosure::plainText('<p>Entered   on the</p>  wrong <br>unit'));
        $this->assertNotNull(DefectNoActionClosure::reasonProblem('<strong>Wrong rig</strong>'));
    }

    public function test_the_close_option_is_hidden_when_the_guard_would_refuse_and_shown_otherwise(): void
    {
        $this->assertContains(DefectCorrectiveActions::DISMISS, DefectCorrectiveActions::applicable($this->defect, false));
        $this->assertNull(DefectCorrectiveActions::closeBlockedReason($this->defect));

        foreach ([['operational_impact' => 'out_of_service'], ['operational_impact' => 'needs_repair'], ['action_status' => 'repair_scheduled'], ['severity' => 'significant']] as $columns) {
            $blocked = $this->openDefect($this->rig, $this->inspection, $this->reporter, 'Item '.md5(json_encode($columns)));
            $blocked->forceFill($columns)->save();

            $this->assertNotContains(DefectCorrectiveActions::DISMISS, DefectCorrectiveActions::applicable($blocked->fresh(), false), json_encode($columns));
            $this->assertStringContainsString('cannot be closed with no action required', (string) DefectCorrectiveActions::closeBlockedReason($blocked->fresh()));
        }
    }

    public function test_correcting_severity_wording_is_the_departments(): void
    {
        $this->assertSame('Correct severity', DefectSeverityCorrection::ACTION_LABEL);
        $this->assertSame('Routine', DefectSeverityCorrection::label('routine'));
        $this->assertSame('Significant damage', DefectSeverityCorrection::label('significant'));
    }
}
