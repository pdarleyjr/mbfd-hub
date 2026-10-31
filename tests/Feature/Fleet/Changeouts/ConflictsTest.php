<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusOperationalAlert;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Section 4.5. A refusal writes nothing and says what to do next; the client keeps the member's work.
 */
final class ConflictsTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, ApparatusChangeout::query()->count());
        $this->assertSame(0, ApparatusFleetEvent::query()->whereNotNull('changeout_id')->count());
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('RE1', $this->reserve->fresh()->designation);
    }

    public function test_the_same_submission_twice_returns_the_first_result(): void
    {
        $alert = $this->swapAlert($this->unit);
        $payload = ['client_submission_id' => (string) Str::uuid(), 'alert_id' => $alert->id, 'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->reserve->id,
            'checklist_version' => app(DailyCheckoutChecklistResolver::class)->resolve($this->unit->fresh())['checklist_version'],
            'exceptions' => [['item_key' => self::AXE, 'handling' => 'leave', 'note' => 'Stays']]];

        $first = $this->postChangeout($this->unit, $payload)->assertCreated();
        // A lost response is retried after the move has happened.
        $second = $this->postChangeout($this->unit, $payload)->assertOk();

        $second->assertJsonPath('replayed', true)->assertJsonPath('changeout.id', $first->json('changeout.id'))->assertJsonPath('changeout.status', 'completed');
        $this->assertSame(1, ApparatusChangeout::query()->count());
        $this->assertSame('E2', $this->reserve->fresh()->designation, 'A replay must not trade the identities back.');
    }

    public function test_the_same_id_with_different_choices_is_refused(): void
    {
        $id = (string) Str::uuid();
        $alert = $this->swapAlert($this->unit);
        $this->moveToReserve(['client_submission_id' => $id, 'alert_id' => $alert->id])->assertCreated();

        $this->postChangeout($this->reserve, [
            'client_submission_id' => $id, 'expected_vehicle_number' => 'V-909', 'to_apparatus_id' => $this->unit->id,
            'exceptions' => [['item_key' => self::AXE, 'handling' => 'leave']],
        ])->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_REPLAY_CONFLICT');

        $this->assertSame(1, ApparatusChangeout::query()->count());
    }

    public function test_the_same_id_from_someone_else_is_not_a_replay(): void
    {
        $id = (string) Str::uuid();
        $payload = ['client_submission_id' => $id, 'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->reserve->id];
        $this->postChangeout($this->unit, $payload)->assertCreated();
        $this->actAsOtherMember();

        $this->postChangeout($this->unit, $payload)->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_REPLAY_CONFLICT');
    }

    public function test_a_stale_expected_vehicle_is_refused_and_nothing_is_written(): void
    {
        $alert = $this->swapAlert($this->unit);

        $response = $this->moveToReserve(['alert_id' => $alert->id, 'expected_vehicle_number' => 'V-999']);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'CHANGEOUT_STALE')
            ->assertJsonPath('current_vehicle_number', 'V-202')
            ->assertJsonPath('current_apparatus_id', $this->unit->id);
        $this->assertStringContainsString('Nothing was saved', $response->json('message'));
        $this->assertNothingWritten();
        $this->assertSame('active', $alert->fresh()->status);
    }

    public function test_a_page_opened_before_the_unit_moved_is_stale_even_though_the_truck_row_still_has_its_number(): void
    {
        $alert = $this->swapAlert($this->unit);
        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated();
        $other = $this->reserveEngine('V-910');

        // The old screen still points at truck V-202, which now carries a different unit.
        $this->postChangeout($this->unit, ['expected_unit_slug' => 'e2', 'to_apparatus_id' => $other->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CHANGEOUT_STALE')
            ->assertJsonPath('current_vehicle_number', 'V-909');
    }

    public function test_a_second_waiting_change_for_a_unit_is_refused_with_the_first(): void
    {
        $first = $this->moveToReserve()->assertCreated();
        $other = $this->reserveEngine('V-910');
        $this->actAsOtherMember();

        $response = $this->postChangeout($this->unit, ['to_apparatus_id' => $other->id]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'CHANGEOUT_ALREADY_PENDING')
            ->assertJsonPath('changeout_id', $first->json('changeout.id'))
            ->assertJsonPath('changeout.to_vehicle_number', 'V-909');
        $this->assertSame(1, ApparatusChangeout::query()->count());
    }

    public function test_the_database_itself_holds_one_waiting_change_per_unit(): void
    {
        $first = $this->changeout($this->moveToReserve()->assertCreated());

        $this->expectException(UniqueConstraintViolationException::class);

        ApparatusChangeout::query()->create([
            ...$first->only(['unit_slug', 'unit_designation', 'from_apparatus_id', 'to_vehicle_number', 'status', 'handoff_status', 'submitted_by_name', 'payload_hash']),
            'client_submission_id' => (string) Str::uuid(),
        ]);
    }

    public function test_exception_order_does_not_change_a_replay(): void
    {
        $id = (string) Str::uuid();
        $axe = ['item_key' => self::AXE, 'handling' => 'leave'];
        $book = ['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => '  Stays '];

        $this->moveToReserve(['client_submission_id' => $id, 'exceptions' => [$axe, $book]])->assertCreated();

        $this->moveToReserve(['client_submission_id' => $id, 'exceptions' => [$book, $axe]])->assertOk()->assertJsonPath('replayed', true);
    }

    public function test_a_direct_commit_is_refused_while_a_decision_is_waiting(): void
    {
        $this->moveToReserve()->assertCreated();
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id])->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_ALREADY_PENDING');

        $this->assertSame('E2', $this->unit->fresh()->designation);
    }

    public function test_a_truck_planned_for_another_unit_is_unavailable(): void
    {
        $this->swapAlert($this->frontline, ['planned_replacement_apparatus_id' => $this->reserve->id]);
        $free = $this->reserveEngine('V-910');
        $alert = $this->swapAlert($this->unit);

        $response = $this->moveToReserve(['alert_id' => $alert->id]);

        $response->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_TRUCK_UNAVAILABLE');
        $this->assertSame([$free->id], array_column($response->json('candidates'), 'apparatus_id'), 'Fresh choices come back with the refusal.');
        $this->assertContains($this->reserve->id, array_column($response->json('other_vehicles'), 'apparatus_id'));
        $this->assertNothingWritten();
    }

    public function test_a_truck_another_unit_is_waiting_on_is_unavailable(): void
    {
        $this->postChangeout($this->frontline, ['to_apparatus_id' => $this->reserve->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id])->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_TRUCK_UNAVAILABLE');

        $this->assertSame('E2', $this->unit->fresh()->designation);
    }

    public function test_the_planned_truck_going_out_of_service_is_unavailable_rather_than_queued(): void
    {
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $this->reserve->id]);
        $this->reserve->forceFill(['status' => 'Out of Service'])->save();

        $this->moveToReserve(['alert_id' => $alert->id])->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_TRUCK_UNAVAILABLE');

        $this->assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_an_alert_for_a_different_unit_is_refused(): void
    {
        $theirs = $this->swapAlert($this->frontline);

        $this->moveToReserve(['alert_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors('alert_id');

        $this->assertNothingWritten();
        $this->assertSame('active', $theirs->fresh()->status);
    }

    public function test_an_inactive_or_unknown_alert_is_refused(): void
    {
        $alert = $this->swapAlert($this->unit);
        $alert->update(['status' => ApparatusOperationalAlert::STATUS_CANCELLED]);

        $this->moveToReserve(['alert_id' => $alert->id])->assertUnprocessable()->assertJsonValidationErrors('alert_id');
        $this->moveToReserve(['alert_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('alert_id');

        $this->assertNothingWritten();
    }

    public function test_a_changeout_of_a_different_unit_never_closes_an_alert(): void
    {
        $unrelated = $this->swapAlert($this->frontline);
        $mine = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $mine->id])->assertCreated();

        $this->assertSame('completed', $mine->fresh()->status);
        $this->assertSame('active', $unrelated->fresh()->status);
    }

    public function test_an_alert_is_not_recorded_twice(): void
    {
        $alert = $this->swapAlert($this->unit);
        $changeout = $this->changeout($this->moveToReserve(['alert_id' => $alert->id, 'exceptions' => [['item_key' => self::AXE, 'handling' => 'review']]])->assertCreated());
        $this->assertSame('active', $alert->fresh()->status);

        // The unit is on the reserve now and the alert is waiting on one line: a second handoff would only confuse it.
        $this->postChangeout($this->reserve, ['alert_id' => $alert->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CHANGEOUT_ALREADY_RECORDED')
            ->assertJsonPath('changeout_id', $changeout->id);
    }

    public function test_the_list_changing_under_named_choices_is_refused(): void
    {
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id, 'checklist_version' => str_repeat('0', 64), 'exceptions' => [['item_key' => self::AXE, 'handling' => 'leave']]])
            ->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_CHECKLIST_CHANGED');

        $this->assertNothingWritten();
    }

    public function test_the_truck_the_unit_is_on_is_not_a_replacement(): void
    {
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->unit->id])->assertUnprocessable()->assertJsonValidationErrors('to_apparatus_id');
    }

    public function test_a_replacement_has_to_be_named(): void
    {
        $this->postChangeout($this->unit, [])->assertUnprocessable()->assertJsonValidationErrors('to_apparatus_id');
    }

    public function test_an_unknown_truck_is_refused(): void
    {
        $this->postChangeout($this->unit, ['to_apparatus_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('to_apparatus_id');
    }

    public function test_a_client_cannot_set_the_changeout_a_return_reverses(): void
    {
        $alert = $this->swapAlert($this->unit);
        $earlier = $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->json('changeout.id');
        $other = $this->reserveEngine('V-910');
        $alertTwo = $this->swapAlert($this->reserve);

        $response = $this->postChangeout($this->reserve, ['alert_id' => $alertTwo->id, 'to_apparatus_id' => $other->id, 'return_of_changeout_id' => $earlier])->assertCreated();

        $this->assertNull($this->changeout($response)->return_of_changeout_id);
    }
}
