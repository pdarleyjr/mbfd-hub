<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectParticipant;
use App\Models\User;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\OperationalDisplayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * "Clear, this is for Logistics" is a real ownership transfer (docs/unified-activity-and-ownership.md): off the
 * Captain 5 queue and every count, into the Logistics queue, still open with its status and crew untouched, audited
 * as owner_transferred, and always reachable through "Bring back".
 */
final class Captain5ClearForLogisticsTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const RESCUE_ITEM = 'patient_compartment-item-5'; // LifePak 15 (Rescue medical: Captain 5's)

    private User $captain;

    private User $reporter;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        $this->captain = $this->officer('Captain', 'Captain Alpha');
        $this->seat($this->captain, 'Captain 5', 'A Shift');
        $this->reporter = $this->newMember('Firefighter Reporter');
        [$this->defect] = $this->reportMissing($this->rescue, $this->reporter, [self::RESCUE_ITEM]);
        Notification::fake(); // forget the new-findings alert
    }

    public function test_clearing_keeps_the_issue_open_and_unchanged_and_tells_nobody(): void
    {
        $before = $this->defect->fresh();

        $this->act('send_to_logistics')->assertOk()
            ->assertJsonPath('issue.open', true)
            ->assertJsonPath('issue.action_status', 'reported')
            ->assertJsonPath('issue.captain5_cleared_by', 'Captain Alpha')
            ->assertJsonPath('issue.actions', [])
            ->assertJsonPath('issue.clearing_actions', ['bring_back_to_captain5']);

        $after = $this->defect->fresh();
        self::assertFalse($after->resolved);
        self::assertSame($before->action_status, $after->action_status);
        self::assertSame($before->status, $after->status);
        self::assertNull($after->resolved_at);
        self::assertSame((string) $before->updated_at, (string) $after->updated_at, 'Logistics sees no change on the issue.');
        self::assertNotNull($after->captain5_cleared_at);
        self::assertSame((int) $this->captain->id, (int) $after->captain5_cleared_by_user_id);
        self::assertSame(ApparatusDefect::OWNER_LOGISTICS, $after->responsible_owner);
        self::assertSame('manual', $after->owner_basis);
        self::assertSame((int) $this->captain->id, (int) $after->owner_set_by_user_id);

        Notification::assertNothingSent();
        self::assertSame(0, ApparatusDefectParticipant::query()->where('apparatus_defect_id', $this->defect->id)->where('user_id', $this->captain->id)->count(), 'Clearing does not sign the person up for updates.');
    }

    public function test_a_cleared_issue_moves_from_the_captain5_queue_into_the_logistics_queue(): void
    {
        $source = app(IssueSourceRegistry::class)->get('apparatus_defect');
        $board = new DashboardQuery(OperationalDisplayWindow::forNow('2026-10-20 10:00:00 America/New_York'));
        $refs = fn (): array => array_map(static fn ($row): string => $row->ref(), $source->rows($this->superAdmin(), $board, 50));
        self::assertNotContains('apparatus_defect:'.$this->defect->id, $refs(), 'A medical item is Captain 5\'s alone: not in the Logistics queue.');

        $this->act('send_to_logistics')->assertOk();

        self::assertTrue(ApparatusDefect::query()->unresolved()->whereKey($this->defect->id)->exists());
        self::assertContains('apparatus_defect:'.$this->defect->id, $refs(), 'The Logistics dashboard lists it now.');
    }

    public function test_a_cleared_issue_leaves_the_queue_and_every_count_on_the_page(): void
    {
        $this->actAs($this->captain);
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonPath('counts.open', 1)->assertJsonCount(0, 'cleared_issues');

        $this->act('send_to_logistics')->assertOk();

        $this->forgetCaptain5Memo();
        $this->actAs($this->captain);
        $workspace = $this->getJson('/api/captain5/workspace')->assertOk()
            ->assertJsonPath('counts.open', 0)
            ->assertJsonPath('counts.needs_acknowledgement', 0)
            ->assertJsonPath('counts.rescue', 0)
            ->assertJsonCount(0, 'issues')
            ->assertJsonCount(1, 'cleared_issues')
            ->assertJsonPath('cleared_issues.0.id', $this->defect->id)
            ->assertJsonPath('cleared_issues.0.captain5_cleared_by', 'Captain Alpha');
        $daily = collect($workspace->json('rescue_dailies'))->firstWhere('apparatus_id', $this->rescue->id);
        self::assertSame(0, $daily['open_issues']);

        $this->getJson('/api/captain5/summary')->assertOk()->assertJsonPath('open_issues', 0);
        $this->getJson('/api/captain5/issues')->assertOk()->assertJsonCount(0, 'issues');
        // Still reachable directly, for a deep link or the cleared list.
        $this->getJson('/api/captain5/issues/'.$this->defect->id)->assertOk()->assertJsonPath('issue.open', true);
    }

    public function test_bring_back_returns_it_to_the_queue_and_the_timeline_records_both(): void
    {
        $this->act('send_to_logistics', ['internal_note' => 'Radio mount belongs to support services.', 'member_message' => 'Never sent'])->assertOk();
        $this->act('bring_back_to_captain5')->assertOk()
            ->assertJsonPath('issue.captain5_cleared_at', null)
            ->assertJsonPath('issue.captain5_cleared_by', null);
        self::assertNull($this->defect->fresh()->captain5_cleared_by_user_id);

        $this->forgetCaptain5Memo();
        $this->actAs($this->captain);
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonPath('counts.open', 1)->assertJsonCount(0, 'cleared_issues');

        $detail = $this->getJson('/api/captain5/issues/'.$this->defect->id)->assertOk();
        self::assertSame(['send_to_logistics'], $detail->json('issue.clearing_actions'));
        self::assertNotContains('send_to_logistics', $detail->json('issue.actions'), 'A page cached before this action existed throws on a name it does not know.');
        self::assertContains('acknowledge', $detail->json('issue.actions'));
        $transfers = collect($detail->json('issue.events'))->where('type', 'owner_transferred')->values();
        self::assertCount(2, $transfers);
        $cleared = $transfers[0];
        self::assertSame('Captain Alpha', $cleared['actor']);
        self::assertSame('Radio mount belongs to support services.', $cleared['internal_note']);
        self::assertNull($cleared['member_message'], 'The crew is never told.');
        self::assertSame($cleared['from'], $cleared['to'], 'No status change happened.');
        self::assertSame('Captain Alpha', $transfers[1]['actor']);
        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, $this->defect->fresh()->responsible_owner);
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('apparatus_issue_messages')->where('apparatus_defect_id', $this->defect->id)->where('body', 'Never sent')->count());
        Notification::assertNothingSent();
    }

    public function test_it_stays_cleared_when_a_later_checkout_observes_it_again(): void
    {
        $this->act('send_to_logistics')->assertOk();
        $this->travel(1)->days();

        $this->reportMissing($this->rescue, $this->reporter, [self::RESCUE_ITEM]);

        self::assertNotNull($this->defect->fresh()->captain5_cleared_at);
        self::assertGreaterThan(0, ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', 'observed_again')->count());
        $this->actAs($this->captain);
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonPath('counts.open', 0)->assertJsonCount(1, 'cleared_issues');
    }

    public function test_when_logistics_closes_it_it_is_logistics_history_and_leaves_the_cleared_list(): void
    {
        $this->act('send_to_logistics')->assertOk();

        app(DefectCorrectiveActionService::class)->resolve($this->defect->id, $this->superAdmin(), ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'Replaced by Logistics.'], (string) Str::uuid());

        $this->forgetCaptain5Memo();
        $this->actAs($this->captain);
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonCount(0, 'cleared_issues')->assertJsonPath('counts.open', 0);
        $closed = $this->getJson('/api/captain5/issues?status=closed')->assertOk();
        self::assertNotContains($this->defect->id, array_column($closed->json('issues'), 'id'), 'Logistics worked and closed it: it is in Logistics history.');
    }

    public function test_logistics_reopening_a_cleared_issue_returns_it_to_captain5(): void
    {
        $this->act('send_to_logistics')->assertOk();
        $logistics = app(DefectCorrectiveActionService::class);
        $logistics->resolve($this->defect->id, $this->superAdmin(), ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'Replaced by Logistics.'], (string) Str::uuid());
        $logistics->reopen($this->defect->id, $this->superAdmin(), ['reason' => 'The replacement was the wrong size.'], (string) Str::uuid());

        self::assertNull($this->defect->fresh()->captain5_cleared_at);
        $this->forgetCaptain5Memo();
        $this->actAs($this->captain);
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonPath('counts.open', 1)->assertJsonCount(0, 'cleared_issues');
    }

    public function test_clearing_is_a_real_change_that_an_open_screen_sees(): void
    {
        $token = DefectCorrectiveActionService::stateToken($this->defect->fresh());

        $this->act('send_to_logistics')->assertOk();

        self::assertNotSame($token, DefectCorrectiveActionService::stateToken($this->defect->fresh()), 'Who works it changed, so a stale screen must refresh before acting.');
    }

    public function test_a_closed_issue_cannot_be_cleared(): void
    {
        $this->act('dismiss', ['reason' => 'Entered against the wrong Rescue by mistake.'])->assertOk();

        $this->act('send_to_logistics')->assertStatus(422)->assertJsonValidationErrors('defect');
        self::assertNull($this->defect->fresh()->captain5_cleared_at);
    }

    public function test_only_someone_who_can_act_may_clear_and_only_in_scope(): void
    {
        $response = $this->act('send_to_logistics', [], $this->reporter);
        $response->assertForbidden();
        self::assertNull($this->defect->fresh()->captain5_cleared_at);

        $this->act('bring_back_to_captain5', [], $this->reporter)->assertForbidden();

        // A non-medical item on an Engine is not Captain 5's to clear, even for Captain 5.
        $outOfScope = ApparatusDefect::query()->create(['apparatus_id' => $this->unit->id, 'compartment' => 'Cab', 'item' => 'Seat', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now()->toDateString()]);
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->postJson('/api/captain5/issues/'.$outOfScope->id.'/actions', [
            'action' => 'send_to_logistics', 'expected_state' => 'reported:0', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        self::assertNull($outOfScope->fresh()->captain5_cleared_at);
    }

    public function test_a_repeat_with_the_same_key_records_once(): void
    {
        $key = (string) Str::uuid();
        $state = $this->state();
        $this->actAs($this->captain);
        $url = '/api/captain5/issues/'.$this->defect->id.'/actions';
        $body = ['action' => 'send_to_logistics', 'expected_state' => $state, 'idempotency_key' => $key];

        $this->postJson($url, $body)->assertOk();
        $this->forgetCaptain5Memo();
        $this->postJson($url, $body)->assertOk()->assertJsonPath('issue.clearing_actions', ['bring_back_to_captain5']);

        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', 'owner_transferred')->count());
    }

    public function test_a_stale_token_is_refused_with_the_current_issue_and_changes_nothing(): void
    {
        $stale = $this->state();
        $this->act('acknowledge')->assertOk();

        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->postJson('/api/captain5/issues/'.$this->defect->id.'/actions', [
            'action' => 'send_to_logistics', 'expected_state' => $stale, 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('issue.action_status', 'acknowledged');

        self::assertNull($this->defect->fresh()->captain5_cleared_at);
        self::assertSame(0, ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', 'owner_transferred')->count());
    }

    private function state(): string
    {
        return DefectCorrectiveActionService::stateToken($this->defect->fresh());
    }

    /** @param array<string, mixed> $data */
    private function act(string $action, array $data = [], ?User $actor = null): TestResponse
    {
        $this->actAs($actor ?? $this->captain);
        $this->forgetCaptain5Memo();

        return $this->postJson('/api/captain5/issues/'.$this->defect->id.'/actions', [
            'action' => $action, 'expected_state' => $this->state(), 'idempotency_key' => (string) Str::uuid(), ...$data,
        ]);
    }
}
