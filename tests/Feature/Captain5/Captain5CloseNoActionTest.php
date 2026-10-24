<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Defects\DefectParticipants;
use App\Support\Fleet\DefectNoActionClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * "Close — No Action Required" through the Captain 5 door: the same reason rules, the same guards
 * (the service owns them, so Captain 5 cannot be a way around them), and one alert per person.
 */
final class Captain5CloseNoActionTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const RESCUE_ITEM = 'compartment_c-item-3'; // Bolt Cutters (Rescue: always in scope)

    private const REASON = 'Entered against the wrong Rescue by mistake.';

    private User $captainA;

    private User $captainB;

    private User $reporter;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        $this->captainA = $this->officer('Captain', 'Captain Alpha');
        $this->captainB = $this->officer('Captain', 'Captain Bravo');
        $this->seat($this->captainA, 'Captain 5', 'A Shift');
        $this->seat($this->captainB, 'Captain 5', 'B Shift');
        $this->reporter = $this->newMember('Firefighter Reporter');
        [$this->defect] = $this->reportMissing($this->rescue, $this->reporter, [self::RESCUE_ITEM]);
        Notification::fake(); // forget the new-findings alert; this suite is about the close
    }

    public function test_the_on_duty_captain5_can_close_with_no_action_and_the_audit_snapshot_and_reason_are_kept(): void
    {
        $this->act($this->captainA, 'dismiss', ['reason' => self::REASON])
            ->assertOk()->assertJsonPath('issue.action_status', 'dismissed')->assertJsonPath('issue.closure', 'closed_no_action')
            ->assertJsonPath('issue.closure_reason', self::REASON)->assertJsonPath('issue.action_label', 'Closed — no action required');

        $event = ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', 'dismissed')->sole();
        self::assertSame((int) $this->captainA->id, (int) $event->actor_user_id);
        self::assertSame('reported', $event->from_status);
        self::assertSame(self::REASON, $event->details['reason']);
        self::assertSame('reported', $event->details['previous_action_status']);
        self::assertSame('shift_captain5', $event->details['actor_context']['authority']);
        self::assertNull($this->defect->fresh()->resolution_type, 'Nothing was repaired.');

        // The Captain 5 history shows the reason; the closed list says why, not "Resolved".
        $this->actAs($this->captainA);
        $detail = $this->getJson('/api/captain5/issues/'.$this->defect->id)->assertOk();
        $closed = collect($detail->json('issue.events'))->firstWhere('type', 'dismissed');
        self::assertSame(self::REASON, $closed['details']['reason']);
        self::assertSame('reported', $closed['details']['previous_action_status']);
    }

    /** @return array<string, array{0: string}> */
    public static function placeholderReasons(): array
    {
        return ['n/a' => ['n/a'], 'none' => ['none'], 'test' => ['test'], 'dot' => ['.'], 'short' => ['Duplicate'], 'restated' => ['No action required']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('placeholderReasons')]
    public function test_the_same_reason_rules_apply_through_captain5(string $reason): void
    {
        $this->act($this->captainA, 'dismiss', ['reason' => $reason])->assertStatus(422);

        self::assertSame('reported', $this->defect->fresh()->action_status);
        self::assertSame(0, ApparatusDefectEvent::query()->where('event_type', 'dismissed')->count());
        self::assertSame(0, DB::table('inspection_notification_deliveries')->where('event_key', 'like', '%:closed:%')->count());
    }

    public function test_captain5_cannot_close_a_significant_or_out_of_service_or_ticketed_issue_and_is_told_to_ask_logistics(): void
    {
        $cases = [
            ['severity' => 'significant'],
            ['operational_impact' => 'out_of_service'],
            ['operational_impact' => 'needs_repair'],
            ['action_status' => 'repair_scheduled'],
            ['action_status' => 'replacement_available'],
        ];
        $original = $this->defect->fresh()->only(['severity', 'operational_impact', 'action_status']);
        foreach ($cases as $columns) {
            $this->defect->forceFill($columns)->save();

            $response = $this->act($this->captainA, 'dismiss', ['reason' => self::REASON])->assertStatus(422);
            $message = (string) $response->json('errors.defect.0');
            self::assertStringContainsString('cannot be closed with no action required', $message);
            // Captain 5 cannot reclassify or correct severity, so it is told whom to ask (and for what).
            self::assertStringContainsString(
                ($columns['severity'] ?? null) === 'significant' ? 'ask Logistics to correct the severity' : 'ask Logistics to correct it',
                $message,
            );
            self::assertStringNotContainsString('Classify impact', $message);
            self::assertStringNotContainsString('Correct severity', $message, 'Captain 5 has no such button.');

            $this->defect->forceFill($original)->save();
        }
        self::assertSame(0, ApparatusDefectEvent::query()->where('event_type', 'dismissed')->count());

        // An open service ticket is the same: Captain 5 cannot see or change Logistics' ticket.
        $ticketId = DB::table('apparatus_service_tickets')->insertGetId([
            'apparatus_id' => $this->rescue->id, 'unit_designation_snapshot' => 'R2', 'origin' => 'hub', 'category' => 'repair',
            'title' => 'Fix', 'description' => 'Fix', 'status' => 'in_progress', 'priority' => 'normal',
            'ticket_number' => 'AST-T-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->defect->forceFill(['service_ticket_id' => $ticketId])->save();
        $message = (string) $this->act($this->captainA, 'dismiss', ['reason' => self::REASON])->assertStatus(422)->json('errors.defect.0');
        self::assertStringContainsString('AST-T-1', $message);
        self::assertSame('reported', $this->defect->fresh()->action_status);
    }

    public function test_a_plain_member_cannot_close_with_no_action_but_any_captain5_page_user_can(): void
    {
        // Since the Captain 5 follow-up (PR #480) there is no duty gating: every Captain 5 page user may
        // do every task, and the service's guards and reason rules still apply to all of them.
        $this->act($this->reporter, 'dismiss', ['reason' => self::REASON])->assertForbidden();
        self::assertSame('reported', $this->defect->fresh()->action_status);

        $this->act($this->captainB, 'dismiss', ['reason' => self::REASON])->assertOk();
        self::assertSame('dismissed', $this->defect->fresh()->action_status);
    }

    public function test_the_reporter_and_every_participant_get_exactly_one_alert_with_the_reason(): void
    {
        $confirmer = $this->newMember('Casey Confirmer');
        app(DefectParticipants::class)->enroll($this->defect, $confirmer, 'confirmed');
        $gone = $this->newMember('Gone Member', active: false);
        app(DefectParticipants::class)->enroll($this->defect, $gone, 'confirmed');
        $bystander = $this->newMember('Bea Bystander');

        $this->act($this->captainA, 'dismiss', ['reason' => self::REASON])->assertOk();

        $event = ApparatusDefectEvent::query()->where('event_type', 'dismissed')->sole();
        foreach ([$this->reporter, $confirmer] as $person) {
            Notification::assertSentToTimes($person, InspectionEventNotification::class, 1);
            Notification::assertSentTo($person, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === Captain5Notifier::KIND_ISSUE_UPDATE
                && str_contains($n->title, 'issue closed, no action required')
                && str_contains($n->body, 'Reason: '.rtrim(self::REASON, '.'))
                && str_contains($n->body, 'It was not repaired or replaced.'));
        }
        Notification::assertNotSentTo($this->captainA, InspectionEventNotification::class); // the actor
        Notification::assertNotSentTo($this->captainB, InspectionEventNotification::class); // off duty, not involved
        Notification::assertNotSentTo($bystander, InspectionEventNotification::class);
        Notification::assertNotSentTo($gone, InspectionEventNotification::class);
        // One alert per person, from one sender: nothing from the Logistics dispatcher's key for the same event.
        self::assertSame(0, DB::table('inspection_notification_deliveries')->where('event_key', 'defect:'.$this->defect->id.':closed:'.$event->id)->count());
        self::assertEqualsCanonicalizing(
            [(int) $this->reporter->id, (int) $confirmer->id],
            DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:defect:'.$this->defect->id.':closed:'.$event->id)->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_a_stuck_captain5_close_alert_is_redelivered_by_the_retry_command(): void
    {
        $this->act($this->captainA, 'dismiss', ['reason' => self::REASON])->assertOk();
        $event = ApparatusDefectEvent::query()->where('event_type', 'dismissed')->sole();
        $key = 'captain5:defect:'.$this->defect->id.':closed:'.$event->id;
        DB::table('inspection_notification_deliveries')->where('event_key', $key)->update(['status' => 'queued', 'updated_at' => now()->subMinutes(30)]);
        Notification::fake();

        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        self::assertSame('dispatched', DB::table('inspection_notification_deliveries')->where('event_key', $key)->where('recipient_user_id', $this->reporter->id)->value('status'));
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
    }

    public function test_reopening_works_from_captain5_and_keeps_the_original_reason_in_history(): void
    {
        $this->act($this->captainA, 'dismiss', ['reason' => self::REASON])->assertOk();
        $this->act($this->captainA, 'reopen', ['reason' => 'It is the right Rescue after all.'])->assertOk()->assertJsonPath('issue.action_status', 'reported');

        $reopened = ApparatusDefectEvent::query()->where('event_type', 'reopened')->sole();
        self::assertSame(self::REASON, $reopened->details['previous_resolution_notes']);
        self::assertSame('It is the right Rescue after all.', $reopened->details['reason']);
        self::assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'dismissed')->count());
    }

    public function test_reopening_from_captain5_tells_everyone_who_was_told_it_was_closed(): void
    {
        $this->act($this->captainA, 'dismiss', ['reason' => self::REASON])->assertOk();
        Notification::fake();

        $this->act($this->captainA, 'reopen', ['reason' => 'It is the right Rescue after all.'])->assertOk();

        $event = ApparatusDefectEvent::query()->where('event_type', 'reopened')->sole();
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->title, 'issue reopened')
            && str_contains($n->body, 'closed with no action required, and Captain 5 reopened it'));
        Notification::assertNotSentTo($this->captainA, InspectionEventNotification::class);
        self::assertSame(1, DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:defect:'.$this->defect->id.':reopened:'.$event->id)->count());
    }

    public function test_the_department_wording_reaches_the_captain5_access_explanation_and_service_messages(): void
    {
        $response = $this->act($this->reporter, 'dismiss', ['reason' => self::REASON]);
        $response->assertForbidden();
        self::assertSame('Close — No Action Required', DefectNoActionClosure::ACTION_LABEL);

        // An on-duty officer who submits an empty reason is asked the department's question.
        $this->act($this->captainA, 'dismiss', ['reason' => ''])->assertStatus(422);
    }

    // ------------------------------------------------------------------ helpers

    private function act(User $actor, string $action, array $data = []): TestResponse
    {
        $this->actAs($actor);
        $this->forgetCaptain5Memo();
        $events = (int) ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->max('id');
        $state = ($this->defect->fresh()->action_status ?: 'reported').':'.$events;

        return $this->postJson('/api/captain5/issues/'.$this->defect->id.'/actions', [
            'action' => $action, 'expected_state' => $state, 'idempotency_key' => (string) Str::uuid(), ...$data,
        ]);
    }
}
