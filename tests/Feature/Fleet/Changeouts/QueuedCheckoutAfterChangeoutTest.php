<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusInspection;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Support\Facades\DB;

/**
 * A checkout queued on a phone posts after the crew changed trucks. It was performed on the old truck and on that truck's
 * list, so it lands there, even though the row now holds a reserve identity and a different template. Real templates:
 * E2 uses engine2 and the reserve row uses default, so the versions really differ.
 */
final class QueuedCheckoutAfterChangeoutTest extends ChangeoutTestCase
{
    public function test_a_queued_checkout_is_accepted_and_processed_on_the_truck_it_was_done_on(): void
    {
        $queued = $this->payload();
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();
        $old = $this->unit->fresh();
        $this->assertFalse($old->isDailyCheckoutRequired());
        $this->assertNotSame($queued['checklist_version'], app(DailyCheckoutChecklistResolver::class)->resolve($old)['checklist_version'], 'The row now carries a different list.');

        $response = $this->postJson($this->submissionUrl(), $queued);

        $response->assertCreated();
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame([$old->id, 'V-202', 'E2', $queued['checklist_version']], [$inspection->apparatus_id, $inspection->vehicle_number, $inspection->designation_at_time, $inspection->checklist_version]);
        $this->assertSame('accepted', $inspection->processing_status);
    }

    public function test_it_does_not_touch_the_new_trucks_meters(): void
    {
        $queued = $this->payload();
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();

        $this->postJson($this->submissionUrl(), $queued)->assertCreated();

        $this->assertSame('1200.0', $this->reserve->fresh()->current_engine_hours);
        $this->assertSame(8000, $this->reserve->fresh()->current_miles);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
    }

    public function test_it_is_accepted_when_the_row_now_has_no_usable_list_at_all(): void
    {
        $queued = $this->payload();
        $this->reserve->forceFill(['daily_checkout_template' => 'pending', 'type' => 'Utility', 'designation' => 'U1', 'unit_id' => 'U1', 'name' => 'Utility 1'])->save();
        $this->unit->forceFill(['type' => 'Utility'])->save();
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();

        $this->postJson($this->submissionUrl(), $queued)->assertCreated();

        $this->assertSame($this->unit->id, ApparatusInspection::query()->sole()->apparatus_id);
    }

    public function test_a_list_retired_by_an_earlier_changeout_is_accepted_again_after_a_return_and_a_second_move(): void
    {
        $manager = $this->fleetOperationsManager();
        $this->actingAsCanonicalUser($manager);
        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'completed');
        $this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
        $this->travel(20)->days();
        $this->actingAsCanonicalUser($manager);
        $queued = $this->payload();
        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'completed');
        $this->actingAsCanonicalUser($this->member);

        $this->postJson($this->submissionUrl(), $queued)->assertCreated();
    }

    public function test_a_reserve_truck_with_no_recent_changeout_is_still_refused(): void
    {
        $this->reserve->forceFill(['daily_checkout_template' => 'engine'])->save();
        $this->unit = $this->reserve->fresh();

        $this->postJson($this->submissionUrl(), $this->payload())
            ->assertStatus(409)->assertJsonPath('message', 'This apparatus is not configured for Daily Checkout.');

        $this->assertSame(0, ApparatusInspection::query()->count());
    }

    public function test_a_changeout_older_than_the_grace_window_is_refused_too(): void
    {
        $queued = $this->payload();
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();
        $hours = (int) config('daily_checkout.inventory_revision_grace_hours', 168);
        ApparatusChangeout::query()->update(['effective_at' => now()->subHours($hours + 1)]);

        $this->postJson($this->submissionUrl(), $queued)->assertStatus(409);
    }

    public function test_the_checklist_get_tells_the_crew_what_stayed_on_the_old_truck(): void
    {
        $response = $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id, 'exceptions' => [
            ['item_key' => $this->itemKey(0, 0), 'handling' => 'leave', 'note' => 'Spare stays'],
        ]])->assertCreated();
        $truck = $this->reserve->fresh();
        $this->assertSame('required', $truck->getRawOriginal('daily_checkout_requirement'));

        $get = $this->getJson("/api/public/apparatuses/{$truck->id}/checklist")->assertOk();

        $get->assertJsonPath('changeout_handoff.from_vehicle_number', 'V-202')
            ->assertJsonPath('changeout_handoff.effective_at', $response->json('changeout.effective_at'))
            ->assertJsonPath('changeout_handoff.left_behind.0.note', 'Spare stays');
        $this->assertCount(1, $get->json('changeout_handoff.left_behind'));
        $this->assertSame($get->json('checklist_version'), app(DailyCheckoutChecklistResolver::class)->versionOf($get->json('checklist')), 'The notice is outside the hash.');
    }

    public function test_a_med_box_left_behind_reads_like_any_other_line(): void
    {
        ['unit' => $unit, 'reserve' => $reserve] = $this->rescuePair();
        $medBox = 'patient_compartment-item-3';

        $this->postChangeout($unit, ['alert_id' => $this->swapAlert($unit)->id, 'to_apparatus_id' => $reserve->id, 'exceptions' => [
            ['item_key' => $medBox, 'handling' => 'leave', 'note' => 'In the locker in the cab'],
        ]])->assertCreated();

        $this->getJson("/api/public/apparatuses/{$reserve->id}/checklist")->assertOk()
            ->assertJsonPath('changeout_handoff.left_behind.0', ['name' => 'Med Box', 'note' => 'In the locker in the cab']);
    }

    public function test_the_notice_ends_after_fourteen_days_or_when_the_row_stops_holding_the_unit(): void
    {
        $alert = $this->swapAlert($this->unit);
        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated();
        $truck = $this->reserve->fresh();

        $this->travel(13)->days();
        $this->actingAsCanonicalUser($this->member);
        $this->getJson("/api/public/apparatuses/{$truck->id}/checklist")->assertOk()->assertJsonPath('changeout_handoff.from_vehicle_number', 'V-202');
        $this->travel(2)->days();
        $this->actingAsCanonicalUser($this->member);
        $this->getJson("/api/public/apparatuses/{$truck->id}/checklist")->assertOk()->assertJsonPath('changeout_handoff', null);
    }

    public function test_the_notice_is_absent_from_a_truck_with_no_changeout_and_costs_one_query(): void
    {
        DB::enableQueryLog();
        $this->getJson("/api/public/apparatuses/{$this->unit->id}/checklist")->assertOk()->assertJsonPath('changeout_handoff', null);

        $queries = collect(DB::getQueryLog())->pluck('query')->filter(static fn (string $sql): bool => str_contains($sql, 'apparatus_changeout'));
        $this->assertCount(1, $queries);
    }

    public function test_the_notice_is_gone_once_the_unit_has_moved_on(): void
    {
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();
        // The row hands the unit over by other means, and something else takes the slug's place.
        Apparatus::query()->whereKey($this->reserve->id)->update(['slug' => 'something-else']);

        $this->getJson("/api/public/apparatuses/{$this->reserve->id}/checklist")->assertOk()->assertJsonPath('changeout_handoff', null);
    }

    private function itemKey(int $compartment, int $item): string
    {
        $checklist = app(DailyCheckoutChecklistResolver::class)->resolve($this->unit->fresh())['checklist'];
        $row = $checklist['compartments'][$compartment];

        return $row['items'][$item]['id'] ?? $row['id'].'-item-'.($item + 1);
    }
}
