<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\User;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\Fleet\DefectEventNarrative;
use App\Support\OperationalDisplayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * A later checkout that finds a routine reported item present closes the issue itself (event resolved_by_checkout),
 * the owner sees it under "Recently resolved" for dashboards.resolved_display_hours, and staff can clear it early.
 * Anything that needs judgement keeps waiting for Verify correction.
 */
final class Captain5ResolvedByCheckoutTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const RESCUE_ITEM = 'patient_compartment-item-5';

    private User $captain;

    private User $reporter;

    private User $finder;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00');
        $this->captain = $this->officer('Captain', 'Captain Alpha');
        $this->seat($this->captain, 'Captain 5', 'A Shift');
        $this->reporter = $this->newMember('Firefighter Reporter');
        $this->finder = $this->newMember('Firefighter Finder');
        [$this->defect] = $this->reportMissing($this->rescue, $this->reporter, [self::RESCUE_ITEM]);
    }

    public function test_a_present_checkout_closes_a_routine_issue_once_and_says_who_and_where(): void
    {
        $status = $this->rescue->fresh()->status;
        Notification::fake();

        $this->reportAllPresent($this->rescue, $this->finder);

        $defect = $this->defect->fresh();
        self::assertSame('resolved', $defect->action_status);
        self::assertTrue($defect->resolved);
        self::assertSame('item_located', $defect->resolution_type);
        self::assertSame((int) $this->finder->id, (int) $defect->resolved_by_user_id);
        self::assertStringStartsWith('Resolved by ', (string) $defect->resolution_notes);
        self::assertStringContainsString(' checkout', (string) $defect->resolution_notes);
        self::assertSame($status, $this->rescue->fresh()->status, 'The unit\'s service status is never touched.');

        $events = ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id);
        self::assertSame(1, (clone $events)->where('event_type', 'resolved_by_checkout')->count());
        self::assertSame(0, (clone $events)->where('event_type', 'appears_corrected')->count(), 'One event tells it, not two competing ones.');
        $event = (clone $events)->where('event_type', 'resolved_by_checkout')->sole();
        self::assertNotNull($event->apparatus_inspection_id);
        self::assertTrue($event->details['member_resolution']);
        self::assertSame('checkout', $event->details['source']);
        self::assertSame((int) $event->apparatus_inspection_id, $event->details['inspection_id']);
        self::assertNotNull($event->details['observation_id']);
        $this->assertDatabaseHas('apparatus_defect_observations', ['apparatus_defect_id' => $defect->id, 'observation' => 'appears_corrected']);
        self::assertSame('Resolved during checkout', DefectEventNarrative::label('resolved_by_checkout'));

        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $workspace = $this->getJson('/api/captain5/workspace')->assertOk()
            ->assertJsonPath('counts.open', 0)->assertJsonCount(0, 'issues')
            ->assertJsonCount(1, 'recently_resolved')
            ->assertJsonPath('recently_resolved.0.id', $this->defect->id)
            ->assertJsonPath('recently_resolved.0.open', false)
            ->assertJsonPath('recently_resolved.0.resolution.source', 'checkout')
            ->assertJsonPath('recently_resolved.0.resolution.by', 'Firefighter Finder')
            ->assertJsonPath('resolved_display_hours', 72);
        self::assertStringStartsWith('Resolved by Firefighter Finder during ', $workspace->json('recently_resolved.0.resolution.text'));
    }

    public function test_the_auto_close_sends_no_push_and_a_retried_pass_cannot_close_it_twice(): void
    {
        Notification::fake();
        $this->reportAllPresent($this->rescue, $this->finder);
        Notification::assertNothingSent();

        // The same findings recorded again (a retried submission) finds the issue closed and does nothing.
        $inspection = $this->defect->fresh()->events()->where('event_type', 'resolved_by_checkout')->sole()->inspection;
        $this->reportAllPresent($this->rescue, $this->finder);
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', 'resolved_by_checkout')->count());
        self::assertNotNull($inspection);
    }

    public function test_a_flag_turns_the_auto_close_off(): void
    {
        config(['captain5.auto_close_on_checkout' => false]);

        $this->reportAllPresent($this->rescue, $this->finder);

        self::assertFalse($this->defect->fresh()->resolved);
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', 'appears_corrected')->count());
    }

    public function test_a_safety_critical_issue_keeps_waiting_and_says_so(): void
    {
        $this->defect->forceFill(['operational_impact' => 'out_of_service'])->save();

        $this->reportAllPresent($this->rescue, $this->finder);

        self::assertFalse($this->defect->fresh()->resolved);
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->getJson('/api/captain5/workspace')->assertOk()
            ->assertJsonCount(0, 'recently_resolved')
            ->assertJsonPath('issues.0.awaiting_verification', true)
            ->assertJsonPath('issues.0.verification.via', 'checkout')
            ->assertJsonPath('issues.0.verification.by', 'Firefighter Finder')
            ->assertJsonPath('issues.0.verification.safety_critical', true);
    }

    public function test_clearing_hides_it_keeps_the_resolution_and_is_idempotent(): void
    {
        $this->reportAllPresent($this->rescue, $this->finder);
        $before = $this->defect->fresh();
        $key = (string) Str::uuid();

        Notification::fake();
        $this->act('clear_resolution', $key)->assertOk();
        $this->act('clear_resolution', $key)->assertOk(); // retry of the same request
        $this->act('clear_resolution')->assertOk(); // a second tap with a new key

        $after = $this->defect->fresh();
        self::assertNotNull($after->resolution_cleared_at);
        self::assertSame((int) $this->captain->id, (int) $after->resolution_cleared_by_user_id);
        self::assertSame('resolved', $after->action_status);
        self::assertSame($before->resolution_notes, $after->resolution_notes);
        self::assertSame($before->resolved_by_user_id, $after->resolved_by_user_id);
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $after->id)->where('event_type', 'resolution_cleared')->count());

        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonCount(0, 'recently_resolved');
        Notification::assertNothingSent();
    }

    public function test_a_resolved_issue_drops_off_after_the_display_window(): void
    {
        $this->reportAllPresent($this->rescue, $this->finder);

        $this->travel(71)->hours();
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonCount(1, 'recently_resolved');

        $this->travel(2)->hours();
        $this->forgetCaptain5Memo();
        $this->actAs($this->captain);
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonCount(0, 'recently_resolved');
        self::assertSame('resolved', $this->defect->fresh()->action_status, 'Still in History and in the record.');
    }

    public function test_only_people_who_may_act_can_clear_and_an_open_issue_cannot_be_cleared(): void
    {
        $this->reportAllPresent($this->rescue, $this->finder);

        $this->actAs($this->finder);
        $this->forgetCaptain5Memo();
        $this->postJson('/api/captain5/issues/'.$this->defect->id.'/actions', [
            'action' => 'clear_resolution', 'expected_state' => $this->state(), 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        self::assertNull($this->defect->fresh()->resolution_cleared_at, 'a crew member cannot clear');

        // An open issue: nothing to clear, nothing written.
        $this->defect->fresh()->forceFill(['action_status' => 'reported'])->save();
        $this->act('clear_resolution')->assertOk();
        self::assertNull($this->defect->fresh()->resolution_cleared_at);
        self::assertSame(0, ApparatusDefectEvent::query()->where('event_type', 'resolution_cleared')->count());
    }

    public function test_reopening_brings_a_cleared_issue_back_to_the_list_when_it_is_resolved_again(): void
    {
        $this->reportAllPresent($this->rescue, $this->finder);
        $this->act('clear_resolution')->assertOk();

        $this->act('reopen', null, ['reason' => 'It was the wrong radio'])->assertOk();

        self::assertNull($this->defect->fresh()->resolution_cleared_at);
        self::assertNull($this->defect->fresh()->resolution_cleared_by_user_id);
    }

    public function test_a_logistics_owned_resolved_row_shows_its_resolution_on_the_board_until_cleared_or_aged_out(): void
    {
        $this->reportAllPresent($this->rescue, $this->finder);
        $this->defect->forceFill(['responsible_owner' => ApparatusDefect::OWNER_LOGISTICS, 'owner_basis' => 'manual'])->save();
        $admin = $this->superAdmin();
        $source = app(IssueSourceRegistry::class)->get('apparatus_defect');
        $window = fn (): DashboardQuery => new DashboardQuery(OperationalDisplayWindow::forNow(now()->format('Y-m-d H:i:s').' America/New_York'));
        $rows = fn (): array => $source->rows($admin, $window(), 50);
        $ref = 'apparatus_defect:'.$this->defect->id;

        $row = collect($rows())->first(static fn ($issue): bool => $issue->ref() === $ref);
        self::assertNotNull($row, 'A resolved issue stays on the board.');
        self::assertStringContainsString('Resolved by Firefighter Finder during ', (string) $row->summary);

        $detail = $source->detail($admin, (string) $this->defect->id);
        self::assertContains('clear_resolution', array_map(static fn ($action): string => $action->key, $detail->actions));

        // Past the old operational-day rule but inside the window: still listed.
        $this->travel(30)->hours();
        self::assertNotNull(collect($rows())->first(static fn ($issue): bool => $issue->ref() === $ref));

        $result = $source->perform($admin, (string) $this->defect->id, 'clear_resolution', [], $row->version);
        self::assertTrue($result->ok ?? true);
        self::assertNull(collect($rows())->first(static fn ($issue): bool => $issue->ref() === $ref), 'Cleared rows leave the board.');
        self::assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->where('event_type', 'resolution_cleared')->count());
        self::assertSame('resolved', $this->defect->fresh()->action_status);
    }

    public function test_a_resolved_board_row_drops_off_after_the_window(): void
    {
        $this->reportAllPresent($this->rescue, $this->finder);
        $this->defect->forceFill(['responsible_owner' => ApparatusDefect::OWNER_LOGISTICS, 'owner_basis' => 'manual'])->save();
        $source = app(IssueSourceRegistry::class)->get('apparatus_defect');

        $this->travel(73)->hours();

        $rows = $source->rows($this->superAdmin(), new DashboardQuery(OperationalDisplayWindow::forNow(now()->format('Y-m-d H:i:s').' America/New_York')), 50);
        self::assertNull(collect($rows)->first(fn ($issue): bool => $issue->ref() === 'apparatus_defect:'.$this->defect->id));
    }

    private function state(): string
    {
        return DefectCorrectiveActionService::stateToken($this->defect->fresh());
    }

    /** @param array<string, mixed> $data */
    private function act(string $action, ?string $key = null, array $data = []): TestResponse
    {
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();

        return $this->postJson('/api/captain5/issues/'.$this->defect->id.'/actions', [
            'action' => $action, 'expected_state' => $this->state(), 'idempotency_key' => $key ?? (string) Str::uuid(), ...$data,
        ]);
    }
}
