<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\User;
use App\Services\Captain5\Captain5InspectionClearing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * Captain 5 clears a Rescue daily checkout he has handled: it leaves the Rescue daily checkouts list, shows under
 * Cleared and can be restored. The submitted record (observations, payload hash, client_submission_id, review and
 * processing state) never changes, and every clear and restore is an append-only review event.
 */
final class Captain5InspectionClearTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private User $captain;

    private User $reporter;

    private ApparatusInspection $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        $this->captain = $this->officer('Captain', 'Captain Alpha');
        $this->seat($this->captain, 'Captain 5', 'A Shift');
        $this->reporter = $this->newMember('Firefighter Reporter');
        $this->reportAllPresent($this->rescue, $this->reporter);
        $this->inspection = $this->latestInspection($this->rescue);
    }

    private function latestInspection(Apparatus $apparatus): ApparatusInspection
    {
        return ApparatusInspection::query()->where('apparatus_id', $apparatus->id)->orderByDesc('id')->firstOrFail();
    }

    /** @return array<string, mixed> today's Rescue 2 row on the Captain 5 page */
    private function rescueRow(): array
    {
        $this->forgetCaptain5Memo();
        $this->actAs($this->captain);

        return collect($this->getJson('/api/captain5/workspace')->assertOk()->json('rescue_dailies'))->firstWhere('apparatus_id', $this->rescue->id);
    }

    /** @return array<string, mixed> */
    private function frozen(ApparatusInspection $inspection): array
    {
        $fresh = $inspection->fresh();

        return [
            'results' => $fresh->results, 'submission_payload_hash' => $fresh->submission_payload_hash,
            'client_submission_id' => $fresh->client_submission_id, 'review_status' => $fresh->review_status,
            'processing_status' => $fresh->processing_status, 'completed_at' => (string) $fresh->completed_at,
            'operator_name' => $fresh->operator_name, 'miles' => $fresh->miles,
        ];
    }

    public function test_clear_takes_the_checkout_off_the_active_list_keeps_the_record_and_logs_it(): void
    {
        $before = $this->frozen($this->inspection);
        $row = $this->rescueRow();
        self::assertSame('submitted', $row['daily']);
        self::assertSame($this->inspection->id, $row['inspection_id']);
        self::assertNull($row['cleared_at']);

        $this->actAs($this->captain);
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/clear", ['note' => 'Talked to the crew, all set.'])
            ->assertOk()
            ->assertJsonPath('inspection_id', $this->inspection->id)
            ->assertJsonPath('cleared_by', 'Captain Alpha')
            ->assertJsonPath('clear_note', 'Talked to the crew, all set.');

        // A repeat (a retry after a dropped connection) changes nothing and writes no second event.
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/clear", ['note' => 'Talked to the crew, all set.'])->assertOk();

        $fresh = $this->inspection->fresh();
        self::assertNotNull($fresh->captain5_cleared_at);
        self::assertSame((int) $this->captain->id, (int) $fresh->captain5_cleared_by_user_id);
        self::assertSame($before, $this->frozen($this->inspection), 'The submitted record is untouched.');

        $events = ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $this->inspection->id)
            ->where('status', Captain5InspectionClearing::EVENT_CLEARED)->get();
        self::assertCount(1, $events);
        $event = $events->first();
        self::assertSame((int) $this->captain->id, (int) $event->changed_by_user_id);
        self::assertNull($event->internal_note, 'The Daily Checkout notes feed is not used for a Captain 5 clear.');
        self::assertSame('Cleared by Captain 5: Talked to the crew, all set.', $event->metadata['title']);
        self::assertSame('Captain Alpha', $event->metadata['actor_name']);
        self::assertSame((string) $before['review_status'], $event->previous_status);

        // The row says it is cleared (the page lists it under Cleared), the Cleared list has it, and the sheet knows.
        $row = $this->rescueRow();
        self::assertNotNull($row['cleared_at']);
        self::assertSame('Captain Alpha', $row['cleared_by']);
        $this->getJson('/api/captain5/inspections/cleared')->assertOk()
            ->assertJsonCount(1, 'inspections')
            ->assertJsonPath('inspections.0.inspection_id', $this->inspection->id)
            ->assertJsonPath('inspections.0.unit', 'R2')
            ->assertJsonPath('inspections.0.clear_note', 'Talked to the crew, all set.');
        $this->getJson("/api/captain5/inspections/{$this->inspection->id}")->assertOk()
            ->assertJsonPath('inspection.cleared_by', 'Captain Alpha');
    }

    public function test_restore_puts_it_back_and_both_steps_stay_in_history(): void
    {
        $this->actAs($this->captain);
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/clear")->assertOk()->assertJsonPath('clear_note', null);
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/restore")->assertOk()
            ->assertJsonPath('cleared_at', null)
            ->assertJsonPath('cleared_by', null);
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/restore")->assertOk();

        $fresh = $this->inspection->fresh();
        self::assertNull($fresh->captain5_cleared_at);
        self::assertNull($fresh->captain5_cleared_by_user_id);
        self::assertSame(
            [Captain5InspectionClearing::EVENT_CLEARED, Captain5InspectionClearing::EVENT_RESTORED],
            ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $this->inspection->id)
                ->whereIn('status', [Captain5InspectionClearing::EVENT_CLEARED, Captain5InspectionClearing::EVENT_RESTORED])
                ->orderBy('id')->pluck('status')->all(),
        );

        self::assertNull($this->rescueRow()['cleared_at']);
        $this->getJson('/api/captain5/inspections/cleared')->assertOk()->assertJsonCount(0, 'inspections');
    }

    public function test_a_new_checkout_after_a_clear_shows_on_the_active_list_again(): void
    {
        $this->actAs($this->captain);
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/clear")->assertOk();

        $this->travel(2)->hours();
        $this->reportAllPresent($this->rescue, $this->reporter);
        $newer = $this->latestInspection($this->rescue);
        self::assertNotSame($this->inspection->id, $newer->id);

        $row = $this->rescueRow();
        self::assertSame($newer->id, $row['inspection_id']);
        self::assertNull($row['cleared_at'], 'Clearing is per checkout: the next one needs his attention again.');
    }

    public function test_only_captain5_can_clear_and_only_a_rescue_checkout(): void
    {
        $this->actAs($this->reporter);
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/clear")->assertForbidden();
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/restore")->assertForbidden();
        $this->getJson('/api/captain5/inspections/cleared')->assertForbidden();
        self::assertNull($this->inspection->fresh()->captain5_cleared_at);

        // An Engine checkout is not on the Captain 5 Rescue list, so it cannot be cleared from it.
        $this->reportAllPresent($this->unit, $this->reporter);
        $engine = $this->latestInspection($this->unit);
        $this->forgetCaptain5Memo();
        $this->actAs($this->captain);
        $this->postJson("/api/captain5/inspections/{$engine->id}/clear")->assertForbidden();
        self::assertNull($engine->fresh()->captain5_cleared_at);

        $this->postJson('/api/captain5/inspections/999999/clear')->assertForbidden();
        $this->postJson("/api/captain5/inspections/{$this->inspection->id}/clear", ['note' => str_repeat('x', 501)])->assertUnprocessable();
        self::assertSame(0, ApparatusInspectionReviewEvent::query()->where('status', Captain5InspectionClearing::EVENT_CLEARED)->count());
    }
}
