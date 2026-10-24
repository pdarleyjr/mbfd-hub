<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionNotificationKind;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\IssueMessageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

final class Captain5MessagingAndInvariantsTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const RESCUE_ITEM = 'compartment_c-item-3';

    private User $captainA;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00');
        $this->captainA = $this->officer('Captain', 'Captain Alpha');
        $this->seat($this->captainA, 'Captain 5', 'A Shift');
    }

    public function test_messages_reach_the_involved_people_not_every_former_crew(): void
    {
        $reporter = $this->newMember('Day One Reporter');
        [$defect] = $this->reportMissing($this->rescue, $reporter, [self::RESCUE_ITEM]);
        $formerCrews = [];
        foreach (['2026-10-21 09:00:00', '2026-10-22 09:00:00'] as $i => $day) {
            $this->travelTo(CarbonImmutable::parse($day, 'America/New_York'));
            $formerCrews[] = $observer = $this->newMember('Observer '.$i);
            $this->reportMissing($this->rescue, $observer, [self::RESCUE_ITEM]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'America/New_York')); // A day again
        $today = $this->newMember('Today Crew');
        $this->reportMissing($this->rescue, $today, [self::RESCUE_ITEM]);

        $this->actAs($this->captainA);
        $this->forgetCaptain5Memo();
        $this->postJson('/api/captain5/issues/'.$defect->id.'/messages', [
            'body' => 'Replacement arrives Friday; use the spare from Rescue 22 until then.',
            'visibility' => 'member', 'client_message_id' => (string) Str::uuid(),
        ])->assertCreated();

        $message = ApparatusIssueMessage::query()->where('apparatus_defect_id', $defect->id)->latest('id')->firstOrFail();
        self::assertSame(ApparatusIssueMessage::CONTEXT_CAPTAIN5, $message->author_context);
        $recipients = DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:message:'.$message->id.':notify')
            ->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        self::assertSame(collect([$reporter->id, $today->id])->sort()->values()->all(), $recipients, 'Reporter + today\'s crew only');
        foreach ($formerCrews as $former) {
            self::assertNotContains((int) $former->id, $recipients, 'Former crews are not flooded');
        }
        self::assertNotContains((int) $this->captainA->id, $recipients, 'The author is never notified');

        // The member sees it labelled as Captain 5 on their own inspection.
        $this->actAs($reporter);
        $this->get('/employee/my-vehicle-inspections/'.$defect->first_reported_inspection_id)->assertOk()->assertSee('Captain 5');
    }

    public function test_member_reply_reaches_the_captain5_officer_working_the_issue_once(): void
    {
        $reporter = $this->newMember('Reporter');
        [$defect] = $this->reportMissing($this->rescue, $reporter, [self::RESCUE_ITEM]);
        $reviewer = $this->newReviewer('Logistics');
        $this->actAs($this->captainA);
        $this->forgetCaptain5Memo();
        $this->postJson('/api/captain5/issues/'.$defect->id.'/actions', [
            'action' => 'acknowledge', 'expected_state' => 'reported:'.ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->max('id'),
            'idempotency_key' => (string) Str::uuid(), 'member_message' => 'Which compartment did you check?',
        ])->assertOk();

        $this->forgetCaptain5Memo();
        $reply = app(IssueMessageService::class)->post($reporter->fresh(), ['defect_id' => $defect->id, 'body' => 'L2 and L3, both empty.', 'client_message_id' => (string) Str::uuid()]);
        self::assertSame(ApparatusIssueMessage::CONTEXT_MEMBER, $reply->author_context);

        $captainCopy = DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:message:'.$reply->id.':reply')->pluck('recipient_user_id')->all();
        self::assertSame([(int) $this->captainA->id], array_map('intval', $captainCopy));
        $reviewerCopy = DB::table('inspection_notification_deliveries')->where('event_key', 'message:'.$reply->id.':notify')->pluck('recipient_user_id')->all();
        self::assertContains((int) $reviewer->id, array_map('intval', $reviewerCopy), 'Existing reviewer routing is unchanged');
        self::assertNotContains((int) $reviewer->id, array_map('intval', $captainCopy), 'No duplicate for reviewers');
    }

    public function test_clarification_request_and_internal_note_visibility(): void
    {
        $reporter = $this->newMember('Reporter');
        [$defect] = $this->reportMissing($this->rescue, $reporter, [self::RESCUE_ITEM]);
        $this->actAs($this->captainA);
        $this->forgetCaptain5Memo();
        $this->postJson('/api/captain5/inspections/'.$defect->first_reported_inspection_id.'/clarification', [
            'body' => 'Were the bolt cutters on the unit at shift change?', 'client_message_id' => (string) Str::uuid(),
        ])->assertCreated();
        $clarification = ApparatusIssueMessage::query()->whereNull('apparatus_defect_id')->latest('id')->firstOrFail();
        self::assertSame(ApparatusIssueMessage::CONTEXT_CAPTAIN5, $clarification->author_context);
        self::assertSame([(int) $reporter->id], DB::table('inspection_notification_deliveries')
            ->where('event_key', 'captain5:message:'.$clarification->id.':notify')->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->all());

        $this->postJson('/api/captain5/issues/'.$defect->id.'/messages', [
            'body' => 'Internal: check the supply room count first.', 'visibility' => 'internal', 'client_message_id' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->where('event_key', 'like', 'captain5:message:%')
            ->whereIn('event_key', ApparatusIssueMessage::query()->where('visibility', 'internal')->pluck('id')->map(fn ($id) => 'captain5:message:'.$id.':notify'))->count(), 'Internal notes never notify');

        $this->actAs($this->officer('Lieutenant'));
        $this->forgetCaptain5Memo();
        $bodies = collect($this->getJson('/api/captain5/issues/'.$defect->id)->assertOk()->json('issue.messages'))->pluck('body');
        self::assertContains('Internal: check the supply room count first.', $bodies->all(), 'Every officer on the Captain 5 page sees the team notes');

        $this->actAs($reporter);
        $this->get('/employee/my-vehicle-inspections/'.$defect->first_reported_inspection_id)->assertOk()
            ->assertDontSee('Internal: check the supply room count first.');
    }

    public function test_admin_workflows_are_unchanged_and_share_state_with_captain5(): void
    {
        $member = $this->newMember('Member');
        [$rescueDefect] = $this->reportMissing($this->rescue, $member, [self::RESCUE_ITEM]);
        [$toughbook] = $this->reportMissing($this->unit, $member, ['front_cab-item-1']);
        $reviewer = $this->newReviewer('Logistics');

        // Logistics still acts on everything (incl. Fleet-only items) through the same engine.
        $engine = app(DefectCorrectiveActionService::class);
        $engine->acknowledge($toughbook->id, $reviewer, ['member_message' => 'Fleet is on it']);
        $engine->updateAction($rescueDefect->id, $reviewer, ['action_status' => 'replacement_available', 'member_message' => 'Pick up at Logistics']);
        self::assertSame('acknowledged', $toughbook->fresh()->action_status);
        self::assertDatabaseHas('inspection_notification_deliveries', ['notification_type' => InspectionNotificationKind::ISSUE_MEMBER_UPDATE, 'recipient_user_id' => $member->id]);
        self::assertDatabaseHas('apparatus_issue_messages', ['apparatus_defect_id' => $rescueDefect->id, 'author_context' => ApparatusIssueMessage::CONTEXT_LOGISTICS]);

        // Captain 5 sees the same live state the admin set.
        $this->actAs($this->captainA);
        $this->forgetCaptain5Memo();
        $this->getJson('/api/captain5/issues/'.$rescueDefect->id)->assertOk()
            ->assertJsonPath('issue.action_status', 'replacement_available')
            ->assertJsonPath('issue.events.1.actor', 'Logistics');

        // Every submission still reaches the admin queue (canonical inspection page renders).
        $this->actAs($reviewer);
        $this->get('/admin/inspections/'.$rescueDefect->first_reported_inspection_id)->assertOk()->assertSee('Bolt Cutters');
    }

    public function test_significant_damage_alerts_admin_reviewers_and_the_captain5_side_once(): void
    {
        $ems = $this->officer('Captain', 'EMS Captain');
        $this->seat($ems, 'EMS', 'D Shift');
        $reviewer = $this->newReviewer('Logistics');
        $member = $this->newMember('Member');
        $this->actAs($member);
        $payload = $this->payload($this->rescue, 2010, 300.5);
        foreach ($payload['compartments'] as $ci => $compartment) {
            foreach ($compartment['items'] as $ii => $item) {
                if ($item['id'] === self::RESCUE_ITEM) {
                    $this->markItem($payload, $ci, $ii, 'Damaged', ['significant' => true, 'notes' => 'Jaw snapped']);
                }
            }
        }
        $this->submit($payload, $this->rescue)->assertSuccessful();
        $defect = ApparatusDefect::query()->where('item_key', self::RESCUE_ITEM)->sole();

        // Reviewers get ONE message for this event: the held-checkout alert, which already names the damaged item.
        self::assertDatabaseHas('inspection_notification_deliveries', ['notification_type' => InspectionNotificationKind::REVIEW_REQUIRED, 'recipient_user_id' => $reviewer->id]);
        self::assertDatabaseMissing('inspection_notification_deliveries', ['notification_type' => InspectionNotificationKind::ISSUE_ESCALATED, 'recipient_user_id' => $reviewer->id]);
        Notification::assertSentToTimes($reviewer, \App\Notifications\Inspections\InspectionEventNotification::class, 1);
        $byKey = fn (string $suffix): array => DB::table('inspection_notification_deliveries')
            ->where('event_key', 'captain5:inspection:'.$defect->first_reported_inspection_id.':'.$suffix)
            ->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        // The on-duty Captain 5 gets ONE notification (headlined as significant damage); the EMS Captain gets one too.
        self::assertSame([(int) $this->captainA->id], $byKey('findings'));
        self::assertSame([(int) $ems->id], $byKey('significant'));
        Notification::assertSentTo($this->captainA, \App\Notifications\Inspections\InspectionEventNotification::class,
            fn ($n): bool => str_contains($n->title, 'significant damage'));
        Notification::assertSentToTimes($this->captainA, \App\Notifications\Inspections\InspectionEventNotification::class, 1);
    }

    public function test_stuck_captain5_notifications_are_redelivered_once_by_the_retry_command(): void
    {
        [$defect] = $this->reportMissing($this->rescue, $this->newMember('Member'), [self::RESCUE_ITEM]);
        $key = 'captain5:inspection:'.$defect->first_reported_inspection_id.':findings';
        DB::table('inspection_notification_deliveries')->where('event_key', $key)->update(['status' => 'queued', 'updated_at' => now()->subMinutes(30)]);
        Notification::fake();

        $this->artisan('inspections:retry-notifications')->assertSuccessful();
        self::assertSame('dispatched', DB::table('inspection_notification_deliveries')->where('event_key', $key)->value('status'));
        Notification::assertSentToTimes($this->captainA, \App\Notifications\Inspections\InspectionEventNotification::class, 1);
        self::assertSame(1, DB::table('inspection_notification_deliveries')->where('event_key', $key)->count(), 'No duplicate ledger rows');
        self::assertSame(Captain5Notifier::KIND_NEW_FINDINGS, DB::table('inspection_notification_deliveries')->where('event_key', $key)->value('notification_type'));
    }
}
