<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Captain5\Captain5Notifier;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

final class Captain5IssueLifecycleTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const RESCUE_ITEM = 'compartment_c-item-3'; // Bolt Cutters (Rescue: always in scope)

    private const LIFEPAK_E2 = 'comp_r1-item-1';         // Engine 2 Life-Pak (medical)

    private const TOUGHBOOK_E2 = 'front_cab-item-1';     // Engine 2 Toughbook (not medical)

    private const COOLER_E2 = 'comp_r4-item-1';          // Engine 2 Water Cooler/Cups (ambiguous)

    private const LIFEPAK_L3 = 'officer_side_panel-item-4';

    private User $captainA;

    private User $captainB;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        $this->captainA = $this->officer('Captain', 'Captain Alpha');
        $this->captainB = $this->officer('Captain', 'Captain Bravo');
        $this->seat($this->captainA, 'Captain 5', 'A Shift');
        $this->seat($this->captainB, 'Captain 5', 'B Shift');
    }

    public function test_full_rescue_lifecycle_across_two_shifts_with_a_different_captain5_each_day(): void
    {
        $alpha = $this->newMember('Firefighter Alpha');
        [$defect] = $this->reportMissing($this->rescue, $alpha, [self::RESCUE_ITEM]);

        // The on-duty (A) Captain 5 alone hears about the new Rescue finding.
        $this->assertLedger('captain5:inspection:'.$defect->first_reported_inspection_id.':findings', [$this->captainA]);

        // Day 1: Captain A acknowledges and records the order.
        $this->act($this->captainA, $defect, 'acknowledge')->assertOk()->assertJsonPath('issue.action_status', 'acknowledged');
        $this->act($this->captainA, $defect, 'ordered', ['vendor_reference' => 'PO-4471', 'expected_delivery' => '2026-10-23',
            'member_message' => 'Bolt cutters ordered, due Friday.'])
            ->assertOk()->assertJsonPath('issue.action_status', 'action_pending')->assertJsonPath('issue.details.vendor_reference', 'PO-4471');
        $defect->refresh();
        self::assertSame(DefectActionStatus::ACTION_PENDING, $defect->action_status);
        self::assertFalse((bool) $defect->resolved, 'Ordered is not replaced');

        $ordered = ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('to_status', 'action_pending')->sole();
        self::assertSame((int) $this->captainA->id, (int) $ordered->actor_user_id);
        self::assertSame('shift_captain5', $ordered->details['actor_context']['authority']);
        self::assertSame('2026-10-20', $ordered->details['actor_context']['duty_date']);
        self::assertSame('A', $ordered->details['actor_context']['shift']);
        self::assertDatabaseHas('apparatus_issue_messages', ['apparatus_defect_id' => $defect->id, 'author_context' => ApparatusIssueMessage::CONTEXT_CAPTAIN5]);
        $this->assertLedger('captain5:defect:'.$defect->id.':update:'.$ordered->id, [$alpha], 'reporter only (today\'s crew is the reporter)');

        // Day 2 (B shift): the B crew still finds it missing; no repeat alert.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:30:00', 'America/New_York'));
        $this->forgetCaptain5Memo();
        $bravo = $this->newMember('Firefighter Bravo');
        $this->reportMissing($this->rescue, $bravo, [self::RESCUE_ITEM]);
        self::assertSame(1, ApparatusDefect::query()->where('apparatus_id', $this->rescue->id)->where('item_key', self::RESCUE_ITEM)->count(), 'One durable issue across shifts');
        self::assertSame(0, DB::table('inspection_notification_deliveries')->where('event_key', 'like', 'captain5:inspection:%')->where('recipient_user_id', $this->captainB->id)->count(), 'Known issue: no new-findings alert');

        // Captain B (now on duty) marks it ready for pickup: reporter + today's crew + Captain A, once each.
        $this->act($this->captainB, $defect, 'available_for_pickup', ['pickup_instructions' => 'Logistics shelf 3'])
            ->assertOk()->assertJsonPath('issue.action_status', 'replacement_available');
        $pickup = ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('to_status', 'replacement_available')->sole();
        self::assertSame('shift_captain5', $pickup->details['actor_context']['authority']);
        self::assertSame('B', $pickup->details['actor_context']['shift']);
        $this->assertLedger('captain5:defect:'.$defect->id.':update:'.$pickup->id, [$alpha, $bravo, $this->captainA]);

        // Closing requires a verified replacement.
        $this->act($this->captainB, $defect, 'replaced', ['resolution_notes' => 'New bolt cutters mounted'])->assertStatus(422);
        $stale = $this->act($this->captainB, $defect, 'replaced', ['resolution_notes' => 'x', 'verified_in_place' => true], 'acknowledged:1');
        $stale->assertStatus(409)->assertJsonPath('issue.action_status', 'replacement_available');
        self::assertSame(DefectActionStatus::REPLACEMENT_AVAILABLE, $defect->fresh()->action_status, '409 wrote nothing');

        $this->act($this->captainB, $defect, 'replaced', ['resolution_notes' => 'New bolt cutters mounted in L2', 'verified_in_place' => true])
            ->assertOk()->assertJsonPath('issue.action_status', 'resolved');
        $defect->refresh();
        self::assertTrue((bool) $defect->resolved);
        self::assertSame('replacement_installed', $defect->resolution_type);
        self::assertSame((int) $this->captainB->id, (int) $defect->resolved_by_user_id);

        // Admin and Captain 5 share one live state and one history.
        $reviewer = $this->newReviewer('Logistics Reviewer');
        $this->actAs($reviewer);
        $this->get('/admin/inspections/'.$defect->first_reported_inspection_id)->assertOk()
            ->assertSee('Captain 5 (on shift)')->assertSee('Captain Bravo');

        // Day 3: the closed issue no longer appears as unresolved in Daily Checkout.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00:00', 'America/New_York'));
        $this->actAs($this->newMember('Firefighter Charlie'));
        $findings = collect($this->getJson($this->checklistUrl($this->rescue))->assertOk()->json('open_findings') ?? []);
        self::assertFalse($findings->contains(fn ($f) => ($f['item_id'] ?? null) === self::RESCUE_ITEM), 'Closed issue must not reappear');

        // History is immutable and reopen keeps the same row.
        $this->forgetCaptain5Memo();
        $this->act($this->captainA, $defect, 'reopen', ['reason' => 'Wrong size delivered'])->assertOk()->assertJsonPath('issue.open', true);
        self::assertGreaterThanOrEqual(5, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->count());
    }

    public function test_engine_and_ladder_issues_are_visible_only_for_mapped_medical_items(): void
    {
        $member = $this->newMember('Engine Firefighter');
        $this->reportMissing($this->unit, $member, [self::LIFEPAK_E2, self::TOUGHBOOK_E2, self::COOLER_E2]);
        $this->reportMissing($this->ladder3, $member, [self::LIFEPAK_L3]);
        $toughbook = ApparatusDefect::query()->where('item_key', self::TOUGHBOOK_E2)->sole();
        $cooler = ApparatusDefect::query()->where('item_key', self::COOLER_E2)->sole();

        $this->actAs($this->captainA);
        $keys = collect($this->getJson('/api/captain5/workspace')->assertOk()->json('issues'))->pluck('item')->all();
        self::assertContains('Life-Pak', $keys);
        self::assertContains('LifePak', $keys, 'Ladder 3 variant');
        self::assertNotContains('Toughbook', $keys, 'Fleet scope stays with Fleet');
        self::assertNotContains('Water Cooler/Cups', $keys, 'Ambiguous items are never shown silently');

        $this->getJson('/api/captain5/issues/'.$toughbook->id)->assertForbidden();
        $this->act($this->captainA, $toughbook, 'acknowledge', [], 'reported:0')->assertForbidden();
        self::assertSame('reported', $toughbook->fresh()->action_status);

        // An administrator classifies the ambiguous item; it becomes visible.
        app(Captain5AdministrationService::class)->classify($this->captain5Administrator(), 'engine2', self::COOLER_E2, 'Water Cooler/Cups', 'medical', 'Used for patient rehab');
        $this->forgetCaptain5Memo();
        $this->actAs($this->captainA);
        $this->getJson('/api/captain5/issues/'.$cooler->id)->assertOk();
    }

    public function test_every_officer_can_act_and_close_issues_whatever_the_shift(): void
    {
        [$defect] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::RESCUE_ITEM]);

        $lieutenant = $this->officer('Lieutenant');
        $this->actAs($lieutenant);
        self::assertNotEmpty($this->getJson('/api/captain5/issues/'.$defect->id)->assertOk()->json('issue.actions'), 'No duty gating: any officer sees the actions');
        $this->act($lieutenant, $defect, 'acknowledge')->assertOk();

        // An off-shift Captain 5 seat holder closes it just as well.
        $this->act($this->captainB, $defect, 'dismiss', ['reason' => 'Item is not carried on this Rescue'])->assertOk()->assertJsonPath('issue.action_status', 'dismissed');

        $this->actAs($this->newMember('Plain Firefighter'));
        $this->getJson('/api/captain5/issues/'.$defect->id)->assertForbidden();
        $this->getJson('/api/captain5/issues/999999')->assertForbidden();
    }

    public function test_retries_are_idempotent_and_concurrent_actors_see_a_conflict(): void
    {
        [$defect] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::RESCUE_ITEM]);
        $token = $this->stateOf($this->captainA, $defect);
        $key = (string) Str::uuid();

        $this->act($this->captainA, $defect, 'acknowledge', [], $token, $key)->assertOk();
        $this->act($this->captainA, $defect, 'acknowledge', [], $token, $key)->assertOk(); // network retry
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('event_type', 'acknowledged')->count());

        // A second officer working from the same stale screen is told to refresh.
        $this->act($this->captainB, $defect, 'ordered', [], $token)->assertStatus(409);
    }

    public function test_a_notification_failure_never_corrupts_issue_state(): void
    {
        [$defect] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::RESCUE_ITEM]);
        Schema::rename('inspection_notification_deliveries', 'inspection_notification_deliveries_off');
        try {
            $this->act($this->captainA, $defect, 'available_for_pickup', ['member_message' => 'Ready at Logistics'])->assertOk();
        } finally {
            Schema::rename('inspection_notification_deliveries_off', 'inspection_notification_deliveries');
        }
        self::assertSame(DefectActionStatus::REPLACEMENT_AVAILABLE, $defect->fresh()->action_status);
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('to_status', 'replacement_available')->count());
    }

    public function test_missing_recipients_fall_back_to_the_captain5_seats(): void
    {
        // No rotation configured: the on-duty Captain 5 cannot be resolved.
        config(['captain5.shift_rotation.enabled' => false]);
        $this->forgetCaptain5Memo();
        [$defect] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::RESCUE_ITEM]);
        $this->assertLedger('captain5:inspection:'.$defect->first_reported_inspection_id.':findings', [$this->captainA, $this->captainB]);
        Notification::assertSentTo($this->captainA, InspectionEventNotification::class,
            fn (InspectionEventNotification $n): bool => $n->kind === Captain5Notifier::KIND_NEW_FINDINGS);
    }

    // ------------------------------------------------------------------ helpers

    private function act(User $actor, ApparatusDefect $defect, string $action, array $data = [], ?string $state = null, ?string $key = null): TestResponse
    {
        $state ??= $this->stateOf($actor, $defect);
        $this->actAs($actor);
        $this->forgetCaptain5Memo();

        return $this->postJson('/api/captain5/issues/'.$defect->id.'/actions', [
            'action' => $action, 'expected_state' => $state, 'idempotency_key' => $key ?? (string) Str::uuid(), ...$data,
        ]);
    }

    private function stateOf(User $viewer, ApparatusDefect $defect): string
    {
        $events = (int) ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->max('id');

        return ($defect->fresh()->action_status ?: 'reported').':'.$events;
    }

    /** @param list<User> $expected */
    private function assertLedger(string $eventKey, array $expected, string $message = ''): void
    {
        $actual = DB::table('inspection_notification_deliveries')->where('event_key', $eventKey)->pluck('recipient_user_id')
            ->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $wanted = collect($expected)->map(fn (User $u): int => (int) $u->id)->sort()->values()->all();
        self::assertSame($wanted, $actual, $message !== '' ? $message : "Recipients of {$eventKey}");
    }
}
