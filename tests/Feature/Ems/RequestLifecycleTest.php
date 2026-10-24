<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class RequestLifecycleTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $member;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->staff = $this->emsCaptain('Cap Tain');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return array{uuid: string, number: string} */
    private function tapeAndGauzeRequest(): array
    {
        return $this->submitRequest($this->member, [
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10],
            ['form' => 'airway-bag', 'item_key' => 'airway.top.gloves', 'quantity' => 3],
        ]);
    }

    private function line(string $uuid, string $itemKey): array
    {
        $this->actingAs($this->staff);

        return collect($this->getJson("/ems/api/requests/{$uuid}")->json('data.lines'))->firstWhere('item_key', $itemKey);
    }

    private function act(string $uuid, string $action, array $payload = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/{$action}", $payload, $key);
    }

    public function test_the_first_staff_action_records_reviewed_once_and_the_status_follows_the_lines(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');
        $gauze = $this->line($uuid, 'als.trauma.gauze');
        $gloves = $this->line($uuid, 'airway.top.gloves');

        $this->act($uuid, 'prepare', ['lines' => [['line_id' => $tape['id'], 'prepared_qty' => 4]]])
            ->assertOk()->assertJsonPath('data.status', 'prepared');
        $request = $this->requestModel($uuid);
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame($this->staff->id, $request->reviewed_by_user_id);
        $this->assertSame(1, $request->events()->where('action', 'reviewed')->count());
        $this->assertSame(0, StockMovement::query()->count(), 'Preparing never moves stock.');

        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('data.status', 'partially_issued');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 3]]])->assertOk();
        $this->assertSame('issued', $this->line($uuid, 'als.trauma.tape')['line_status']);
        $this->assertSame('partially_issued', $this->requestModel($uuid)->status, 'Other lines are still open.');

        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $gauze['id'], 'quantity' => 10, 'supply_item_id' => $this->supplyItem('sr.t.01')->id]]])->assertOk();
        $this->act($uuid, 'not-issued', ['line_id' => $gloves['id'], 'reason' => 'Not stocked this month'])
            ->assertOk()->assertJsonPath('data.status', 'issued');

        $this->assertSame(1, $this->requestModel($uuid)->events()->where('action', 'reviewed')->count());
        $this->assertSame(['submitted', 'reviewed', 'prepared', 'issued', 'issued', 'issued', 'not_issued'], $this->requestModel($uuid)->events()->pluck('action')->all());
    }

    public function test_the_members_list_counts_lines_that_have_something_issued(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 1]]])->assertOk();

        $this->actingAs($this->member)->getJson('/employee/forms/api/ems/requests')->assertOk()
            ->assertJsonPath('data.0.line_count', 3)
            ->assertJsonPath('data.0.issued_line_count', 1);
    }

    public function test_issuing_moves_stock_only_for_a_line_that_maps_to_one_chosen_item_with_an_explicit_conversion(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');
        $gauze = $this->line($uuid, 'als.trauma.gauze');
        $gloves = $this->line($uuid, 'airway.top.gloves');
        $this->assertCount(2, $gauze['supply_candidates'], 'Gauze maps to two supply room items, so a person must choose.');
        $this->assertCount(1, $tape['supply_candidates']);

        // One candidate, units in each: stock moves.
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 3]]])->assertOk();
        $movement = StockMovement::query()->sole();
        $this->assertSame($this->supplyItem('sr.t.02')->id, $movement->supply_item_id);
        $this->assertSame(-3, $movement->quantity_base);
        $this->assertSame('issued', $movement->movement_type);

        // Two candidates and nothing chosen: recorded as unmatched, no movement.
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $gauze['id'], 'quantity' => 4]]])->assertOk();
        // The line counts in "set" but the supply item is packed in cases of unknown size: never guessed.
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $gloves['id'], 'quantity' => 1]]])->assertOk();
        $this->assertSame(1, StockMovement::query()->count());

        $events = $this->requestModel($uuid)->events()->where('action', 'issued')->get();
        $this->assertSame(['applied', 'unmatched', 'unmatched'], $events->map(fn ($e) => $e->payload['stock_effect'])->all());
        $this->assertStringContainsString('Several supply room items', $events[1]->payload['stock_note']);
        $this->assertStringContainsString('no stated size', $events[2]->payload['stock_note']);

        // A chosen item with an explicit factor: moves stock.
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $gauze['id'], 'quantity' => 6, 'supply_item_id' => $this->supplyItem('sr.t.07')->id]]])->assertOk();
        $this->assertSame(-6, StockMovement::query()->where('supply_item_id', $this->supplyItem('sr.t.07')->id)->sole()->quantity_base);
    }

    public function test_the_controlled_narcotics_box_is_recorded_as_yes_or_no_and_never_touches_stock(): void
    {
        $uuid = $this->submitRequest($this->member, [['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => true]])['uuid'];
        $line = $this->line($uuid, 'als.rescue.narcs-box');

        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $line['id'], 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('data.status', 'issued');
        $this->assertSame(0, StockMovement::query()->count());
        $event = $this->requestModel($uuid)->events()->where('action', 'issued')->sole();
        $this->assertSame('none', $event->payload['stock_effect']);
        $this->assertStringContainsString('controlled-substance procedure', $event->payload['stock_note']);
    }

    public function test_a_no_answer_needs_nothing_issued(): void
    {
        $uuid = $this->submitRequest($this->member, [
            ['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => false],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 1],
        ])['uuid'];
        $narcs = $this->line($uuid, 'als.rescue.narcs-box');
        $this->assertSame('not_issued', $narcs['line_status']);

        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $narcs['id'], 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'nothing_requested');
        $tape = $this->line($uuid, 'als.trauma.tape');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 1]]])->assertOk()->assertJsonPath('data.status', 'issued');
    }

    public function test_issuing_more_than_requested_needs_a_reason_that_is_recorded(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');

        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 5]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'over_issue_needs_reason');
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, $this->requestModel($uuid)->lines->sum('issued_qty'));

        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 5, 'reason' => 'Crew restocking two bags']]])->assertOk();
        $event = $this->requestModel($uuid)->events()->where('action', 'issued')->sole();
        $this->assertTrue($event->payload['over_issue']);
        $this->assertSame('Crew restocking two bags', $event->payload['reason']);
        $this->assertSame(5, $this->requestModel($uuid)->lines->firstWhere('item_key', 'als.trauma.tape')->issued_qty);
    }

    public function test_not_issued_and_close_need_a_reason_and_a_closed_request_can_be_reopened(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $gloves = $this->line($uuid, 'airway.top.gloves');

        $this->act($uuid, 'not-issued', ['line_id' => $gloves['id']])->assertStatus(422)->assertJsonPath('error.message', 'Give a reason this item is not being issued.');
        $this->act($uuid, 'close', [])->assertStatus(422);
        $this->act($uuid, 'close', ['reason' => 'No action required'])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.close_reason', 'No action required');

        $tape = $this->line($uuid, 'als.trauma.tape');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 1]]])->assertStatus(409)->assertJsonPath('error.code', 'request_closed');
        $this->act($uuid, 'reopen', [])->assertStatus(422);
        $this->act($uuid, 'close', ['reason' => 'again'])->assertStatus(409);

        $this->act($uuid, 'reopen', ['reason' => 'Crew called back'])->assertOk()->assertJsonPath('data.status', 'reviewed');
        $this->assertNull($this->requestModel($uuid)->closed_at);
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 1]]])->assertOk();
        $this->assertSame(['submitted', 'reviewed', 'closed', 'reopened', 'issued'], $this->requestModel($uuid)->events()->pluck('action')->all());
        $this->act($uuid, 'reopen', ['reason' => 'x y z'])->assertStatus(409)->assertJsonPath('error.code', 'request_not_closed');
    }

    public function test_a_request_can_only_be_cancelled_by_its_owner_before_anything_is_issued(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 1]]])->assertOk();

        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$uuid}/cancel", [])->assertStatus(409)->assertJsonPath('error.code', 'cannot_cancel');
        $this->assertSame('partially_issued', $this->requestModel($uuid)->status);

        $fresh = $this->submitRequest($this->member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]])['uuid'];
        $this->act($fresh, 'prepare', ['lines' => [['line_id' => $this->line($fresh, 'airway.top.bvm')['id'], 'prepared_qty' => 1]]])->assertOk();
        $this->emsPost($this->staff, "/employee/forms/api/ems/requests/{$fresh}/cancel", [])->assertNotFound();
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$fresh}/cancel", ['reason' => 'Ordered by mistake'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Ordered by mistake');

        $request = $this->requestModel($fresh);
        $this->assertNotNull($request->cancelled_at);
        $this->act($fresh, 'close', ['reason' => 'whatever'])->assertStatus(409)->assertJsonPath('error.code', 'request_cancelled');
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$fresh}/cancel", [], 'another-key-123')->assertStatus(409);
    }

    public function test_corrections_are_compensating_movements_and_events_never_edits(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 4]]])->assertOk();
        $original = $this->requestModel($uuid)->events()->where('action', 'issued')->sole();
        $originalMovement = StockMovement::query()->sole();
        $originalPayload = $original->payload;

        $this->act($uuid, 'correct', ['event_id' => $original->id, 'new_quantity' => 2])->assertStatus(422)->assertJsonPath('error.message', 'Give a reason for the correction.');
        $this->act($uuid, 'correct', ['event_id' => $original->id, 'new_quantity' => 4, 'reason' => 'typo'])->assertStatus(422)->assertJsonPath('error.code', 'no_change');
        $this->act($uuid, 'correct', ['event_id' => $original->id, 'new_quantity' => 2, 'reason' => 'Only gave two'])->assertOk();

        $this->assertSame(2, $this->requestModel($uuid)->lines->firstWhere('item_key', 'als.trauma.tape')->issued_qty);
        $movements = StockMovement::query()->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(-4, $movements[0]->quantity_base, 'The original movement is untouched.');
        $this->assertSame('adjusted', $movements[1]->movement_type);
        $this->assertSame(2, $movements[1]->quantity_base);
        $this->assertSame($originalMovement->id, $movements[1]->corrects_movement_id);
        $this->assertSame($originalPayload, $original->fresh()->payload, 'The original event is untouched.');
        $this->assertSame(['issued', 'issue_corrected'], $this->requestModel($uuid)->events()->whereIn('action', ['issued', 'issue_corrected'])->pluck('action')->all());

        // Correct it again, up to three: only the difference moves.
        $this->act($uuid, 'correct', ['event_id' => $original->id, 'new_quantity' => 3, 'reason' => 'Third one found'])->assertOk();
        $this->assertSame(-1, StockMovement::query()->orderByDesc('id')->first()->quantity_base);
        $this->assertSame(3, $this->requestModel($uuid)->lines->firstWhere('item_key', 'als.trauma.tape')->issued_qty);
        $this->assertSame(-3, StockMovement::query()->sum('quantity_base') * 1, 'Net stock effect equals what was finally issued.');

        // Back to zero returns everything.
        $this->act($uuid, 'correct', ['event_id' => $original->id, 'new_quantity' => 0, 'reason' => 'Issued to the wrong unit'])->assertOk();
        $this->assertSame(0, (int) StockMovement::query()->sum('quantity_base'));
        $this->assertSame(0, $this->requestModel($uuid)->lines->firstWhere('item_key', 'als.trauma.tape')->issued_qty);
    }

    public function test_a_wrong_item_correction_returns_stock_to_the_old_item_and_takes_it_from_the_right_one(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $gauze = $this->line($uuid, 'als.trauma.gauze');
        $first = $this->supplyItem('sr.t.01');
        $second = $this->supplyItem('sr.t.07');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $gauze['id'], 'quantity' => 10, 'supply_item_id' => $first->id]]])->assertOk();
        $event = $this->requestModel($uuid)->events()->where('action', 'issued')->sole();

        $this->act($uuid, 'correct', ['event_id' => $event->id, 'new_quantity' => 10, 'supply_item_id' => $second->id, 'reason' => 'Pulled from the wrong shelf'])->assertOk();

        $this->assertSame(0, (int) StockMovement::query()->where('supply_item_id', $first->id)->sum('quantity_base'));
        $this->assertSame(-10, (int) StockMovement::query()->where('supply_item_id', $second->id)->sum('quantity_base'));
        $this->assertSame(10, $this->requestModel($uuid)->lines->firstWhere('item_key', 'als.trauma.gauze')->issued_qty);
    }

    public function test_attaching_an_item_to_an_unmatched_issuance_applies_the_stock_effect_once(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $gauze = $this->line($uuid, 'als.trauma.gauze');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $gauze['id'], 'quantity' => 5]]])->assertOk();
        $this->assertSame(0, StockMovement::query()->count());
        $event = $this->requestModel($uuid)->events()->where('action', 'issued')->sole();

        $this->act($uuid, 'correct', ['event_id' => $event->id, 'new_quantity' => 5, 'supply_item_id' => $this->supplyItem('sr.t.01')->id, 'reason' => 'Chose the item'])->assertOk();
        $this->assertSame(-5, (int) StockMovement::query()->sum('quantity_base'));
        $this->assertSame(5, $this->requestModel($uuid)->lines->firstWhere('item_key', 'als.trauma.gauze')->issued_qty);
    }

    public function test_the_same_idempotency_key_runs_the_action_once(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');
        $payload = ['issuances' => [['line_id' => $tape['id'], 'quantity' => 2]]];

        $first = $this->act($uuid, 'issue', $payload, 'issue-once-0001')->assertOk()->assertJsonPath('meta.replayed', false);
        $second = $this->act($uuid, 'issue', $payload, 'issue-once-0001')->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(2, $this->requestModel($uuid)->lines->firstWhere('item_key', 'als.trauma.tape')->issued_qty);

        // A failed attempt leaves no key behind, so it can be fixed and retried.
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 50]]], 'issue-fail-0001')->assertStatus(422);
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 1]]], 'issue-fail-0001')->assertOk()->assertJsonPath('meta.replayed', false);
    }

    public function test_a_line_from_another_request_is_refused(): void
    {
        $one = $this->tapeAndGauzeRequest()['uuid'];
        $two = $this->tapeAndGauzeRequest()['uuid'];
        $lineOfTwo = $this->line($two, 'als.trauma.tape');

        $this->act($one, 'issue', ['issuances' => [['line_id' => $lineOfTwo['id'], 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'unknown_line');
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_messages_flow_both_ways_and_a_member_sees_a_safe_history(): void
    {
        $uuid = $this->tapeAndGauzeRequest()['uuid'];
        $tape = $this->line($uuid, 'als.trauma.tape');
        $this->act($uuid, 'issue', ['issuances' => [['line_id' => $tape['id'], 'quantity' => 2]]])->assertOk();
        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/messages", ['body' => '  Pick up the rest tomorrow  '])->assertCreated()->assertJsonPath('data.body', 'Pick up the rest tomorrow');
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$uuid}/messages", ['body' => 'Will do'])->assertCreated()->assertJsonPath('data.author_context', 'member');
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$uuid}/messages", ['body' => '   '])->assertStatus(422)->assertJsonPath('error.message', 'Write a message before you send.');

        $detail = $this->actingAs($this->member)->getJson("/employee/forms/api/ems/requests/{$uuid}")->assertOk();
        $this->assertSame(['Pick up the rest tomorrow', 'Will do'], collect($detail->json('data.messages'))->pluck('body')->all());
        $this->assertSame('captain5', $detail->json('data.messages.0.author_context'));
        $issued = collect($detail->json('data.history'))->firstWhere('action', 'issued');
        $this->assertSame(['quantity' => 2, 'label' => 'Tape 1"'], $issued['payload'], 'Members never see stock detail.');
        $this->assertSame(['submitted', 'reviewed', 'issued'], collect($detail->json('data.history'))->pluck('action')->all());

        $staffDetail = $this->actingAs($this->staff)->getJson("/ems/api/requests/{$uuid}")->assertOk();
        $this->assertArrayHasKey('stock_effect', collect($staffDetail->json('data.history'))->firstWhere('action', 'issued')['payload']);
        $this->assertCount(InventoryRequestEvent::query()->where('request_id', $this->requestModel($uuid)->id)->count(), $staffDetail->json('data.history'));
        $this->assertSame(InventoryRequest::PARTIALLY_ISSUED, $this->requestModel($uuid)->status);
    }
}
