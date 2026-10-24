<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class SupplyRoomStockTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->staff = $this->emsCaptain('Cap Tain');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function issueTape(int $quantity): string
    {
        $member = $this->emsMember();
        $uuid = $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => $quantity]])['uuid'];
        $this->actingAs($this->staff);
        $line = $this->getJson("/ems/api/requests/{$uuid}")->json('data.lines.0');
        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $line['id'], 'quantity' => $quantity]]])->assertOk();

        return $uuid;
    }

    /** Confirm what the preview showed, as the screen does. */
    private function confirmReconciliation(string $key, ?int $expected = null): \Illuminate\Testing\TestResponse
    {
        $expected ??= (int) $this->actingAs($this->staff)->getJson('/ems/api/supply/reconciliation')->assertOk()->json('data.last_movement_id');

        return $this->emsPost($this->staff, '/ems/api/supply/reconciliation', ['expected_last_movement_id' => $expected], $key);
    }

    /** @return array<string, mixed> */
    private function previewRow(string $rowKey): array
    {
        $this->actingAs($this->staff);
        $rows = collect($this->getJson('/ems/api/supply/reconciliation')->assertOk()->json('data.rows'));

        return $rows->firstWhere('supply_item_id', $this->supplyItem($rowKey)->id);
    }

    public function test_items_show_flags_thresholds_and_no_current_quantity_before_the_first_count(): void
    {
        $this->actingAs($this->staff);
        $items = collect($this->getJson('/ems/api/supply/items?per_page=50')->assertOk()->json('data'));

        $tape = $items->firstWhere('name', 'Tape 1 inch');
        $this->assertSame(12, $tape['threshold_qty_base']);
        $this->assertSame('< 12U', $tape['threshold_source_text']);
        $this->assertNull($tape['current_quantity']);
        $this->assertSame('Not counted yet', $tape['current_basis_label']);
        $this->assertStringContainsString('unverified', $tape['suppliers_note']);
        $this->assertSame('BT', $tape['suppliers'][0]['code']);

        $gloves = $items->firstWhere('name', 'Gloves Medium');
        $this->assertNull($gloves['threshold_qty_base'], 'An unknown conversion is flagged, not guessed.');
        $this->assertTrue($gloves['needs_review']);

        $cardizem = $items->firstWhere('name', 'Cardizem 25mg');
        $this->assertTrue($cardizem['refrigerated']);

        $fentanyl = $items->firstWhere('name', 'Fentanyl (Narcotics Locker)');
        $this->assertFalse($fentanyl['tracks_stock']);
        $this->assertNull($fentanyl['current_quantity']);
        $this->assertStringContainsString('controlled-substance procedure', $fentanyl['controlled_note']);

        $this->assertSame(['A2', 'A3', 'B1', 'C1', 'D1', 'LOCK'], collect($this->getJson('/ems/api/supply/locations')->json('data'))->pluck('code')->sort()->values()->all());
        $this->assertCount(1, $this->getJson('/ems/api/supply/items?q=stetho')->json('data'));
        $this->assertCount(2, $this->getJson('/ems/api/supply/items?location=A2')->json('data'));
    }

    public function test_a_request_never_changes_stock_only_issuance_does_once(): void
    {
        $this->finalizeCount($this->staff, ['sr.t.02' => 30]);
        $member = $this->emsMember();
        $uuid = $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4]])['uuid'];

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(30, $this->stockOf($this->staff, 'sr.t.02')['current_quantity']);

        $line = $this->getJson("/ems/api/requests/{$uuid}")->json('data.lines.0');
        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/prepare", ['lines' => [['line_id' => $line['id'], 'prepared_qty' => 4]]])->assertOk();
        $this->assertSame(30, $this->stockOf($this->staff, 'sr.t.02')['current_quantity'], 'Preparing does not leave stock.');

        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $line['id'], 'quantity' => 4]]], 'issue-key-0001')->assertOk();
        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $line['id'], 'quantity' => 4]]], 'issue-key-0001')->assertOk()->assertJsonPath('meta.replayed', true);
        $stock = $this->stockOf($this->staff, 'sr.t.02');
        $this->assertSame(26, $stock['current_quantity']);
        $this->assertSame('calculated', $stock['current_basis']);
        $this->assertStringStartsWith('Calculated since count ', $stock['current_basis_label']);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_counts_are_resumable_convert_only_with_explicit_factors_and_become_immutable(): void
    {
        $start = $this->emsPost($this->staff, '/ems/api/supply/counts', ['location_code' => 'A2'])->assertCreated();
        $uuid = $start->json('data.id');
        $this->assertFalse($start->json('data.resumed'));
        $this->assertSame(['Gauze 4x4', 'Tape 1 inch'], collect($start->json('data.items'))->pluck('name')->sort()->values()->all());

        $again = $this->emsPost($this->staff, '/ems/api/supply/counts', ['location_code' => 'A2'])->assertOk();
        $this->assertTrue($again->json('data.resumed'));
        $this->assertSame($uuid, $again->json('data.id'));
        $this->emsPost($this->staff, '/ems/api/supply/counts', ['location_code' => 'ZZ'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_location');

        $tape = $this->supplyItem('sr.t.02');
        $gauze = $this->supplyItem('sr.t.01');
        $this->actingAs($this->staff)->putJson("/ems/api/supply/counts/{$uuid}/lines", ['lines' => [
            ['supply_item_id' => $tape->id, 'boxes' => 2, 'each' => 5],   // 2 boxes of 24 + 5 = 53
            ['supply_item_id' => $gauze->id, 'cases' => 1],               // no case size stated: flagged, never guessed
        ]], ['Idempotency-Key' => 'save-count-0001'])->assertOk();
        $lines = InventorySnapshotLine::query()->get()->keyBy('supply_item_id');
        $this->assertSame(53, $lines[$tape->id]->quantity_base);
        $this->assertSame('counted', $lines[$tape->id]->value_source);
        $this->assertNull($lines[$gauze->id]->quantity_base);
        $this->assertSame('not_counted', $lines[$gauze->id]->value_source);
        $this->assertSame(['unknown_conversion'], $lines[$gauze->id]->warnings);

        // Resume: the saved entries come back; clear one by sending nothing typed.
        $resumed = $this->actingAs($this->staff)->getJson("/ems/api/supply/snapshots/{$uuid}")->assertOk();
        $this->assertSame(53, collect($resumed->json('data.items'))->firstWhere('id', $tape->id)['quantity_base']);
        $this->actingAs($this->staff)->putJson("/ems/api/supply/counts/{$uuid}/lines", ['lines' => [['supply_item_id' => $gauze->id]]], ['Idempotency-Key' => 'save-count-0002'])->assertOk();
        $this->assertSame(1, InventorySnapshotLine::query()->count());

        // Wrong location and controlled items are refused.
        $this->actingAs($this->staff)->putJson("/ems/api/supply/counts/{$uuid}/lines", ['lines' => [['supply_item_id' => $this->supplyItem('sr.t.03')->id, 'each' => 1]]], ['Idempotency-Key' => 'save-count-0003'])
            ->assertStatus(422)->assertJsonPath('error.code', 'wrong_location');
        $this->actingAs($this->staff)->putJson("/ems/api/supply/counts/{$uuid}/lines", ['lines' => [['supply_item_id' => $this->supplyItem('sr.t.06')->id, 'each' => 1]]], ['Idempotency-Key' => 'save-count-0004'])
            ->assertStatus(422)->assertJsonPath('error.code', 'item_not_counted');

        $final = $this->emsPost($this->staff, "/ems/api/supply/counts/{$uuid}/finalize")->assertOk();
        $this->assertSame('final', $final->json('data.status'));
        $this->assertSame(1, $final->json('data.version'));
        $this->assertSame('Cap Tain', $final->json('data.finalized_name'));
        $this->assertNotNull($final->json('data.finalized_at'));

        // Immutable: no more lines, no model edits, no deletes.
        $this->actingAs($this->staff)->putJson("/ems/api/supply/counts/{$uuid}/lines", ['lines' => [['supply_item_id' => $tape->id, 'each' => 99]]], ['Idempotency-Key' => 'save-count-0005'])
            ->assertStatus(409)->assertJsonPath('error.code', 'count_final');
        $this->emsPost($this->staff, "/ems/api/supply/counts/{$uuid}/finalize")->assertStatus(409);
        $this->actingAs($this->staff)->deleteJson("/ems/api/supply/counts/{$uuid}", [], ['Idempotency-Key' => 'discard-0001'])->assertStatus(409);
        $snapshot = InventorySnapshot::query()->where('uuid', $uuid)->firstOrFail();
        foreach ([fn () => $snapshot->update(['notes' => 'x']), fn () => $snapshot->delete(), fn () => $snapshot->lines()->first()->update(['quantity_base' => 1]), fn () => $snapshot->lines()->first()->delete(),
            fn () => InventorySnapshotLine::query()->create(['snapshot_id' => $snapshot->id, 'supply_item_id' => $gauze->id, 'item_name' => 'x', 'value_source' => 'counted'])] as $attempt) {
            try {
                $attempt();
                $this->fail('A final snapshot changed.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $counted = $this->stockOf($this->staff, 'sr.t.02');
        $this->assertSame(53, $counted['current_quantity']);
        $this->assertSame('counted', $counted['current_basis']);
        $this->assertStringStartsWith('Counted ', $counted['current_basis_label']);
    }

    public function test_an_empty_count_cannot_be_finalized_and_a_draft_can_be_discarded(): void
    {
        $uuid = $this->emsPost($this->staff, '/ems/api/supply/counts', [])->json('data.id');
        $this->emsPost($this->staff, "/ems/api/supply/counts/{$uuid}/finalize")->assertStatus(422)->assertJsonPath('error.code', 'empty_count');
        $this->actingAs($this->staff)->deleteJson("/ems/api/supply/counts/{$uuid}", [], ['Idempotency-Key' => 'discard-0002'])->assertOk();
        $this->assertSame(0, InventorySnapshot::query()->count());
    }

    public function test_the_reconciliation_preview_shows_previous_issued_received_adjusted_and_calculated(): void
    {
        $this->finalizeCount($this->staff, ['sr.t.02' => 30, 'sr.t.03' => 1]);
        $this->issueTape(4);
        $tape = $this->supplyItem('sr.t.02');
        $order = $this->emsPost($this->staff, '/ems/api/supply/orders', ['supply_item_id' => $tape->id, 'packages' => 2])->assertCreated();
        $this->assertSame(48, $order->json('data.quantity_base'));
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'received')->count(), 'Placing an order never moves stock.');
        $this->emsPost($this->staff, '/ems/api/supply/orders/'.$order->json('data.id').'/receive')->assertOk()->assertJsonPath('data.status', 'received');
        $this->emsPost($this->staff, '/ems/api/supply/adjustments', ['supply_item_id' => $tape->id, 'quantity_base' => -1, 'reason' => 'Damaged box'])->assertCreated();

        $row = $this->previewRow('sr.t.02');
        $this->assertSame(30, $row['previous_quantity']);
        $this->assertSame(4, $row['issued']);
        $this->assertSame(48, $row['received']);
        $this->assertSame(-1, $row['adjusted']);
        $this->assertSame(73, $row['calculated_quantity']);
        $this->assertSame(12, $row['threshold_qty_base']);
        $this->assertFalse($row['below_threshold']);
        $this->assertSame([], $row['warnings']);

        $never = $this->previewRow('sr.t.01');
        $this->assertNull($never['previous_quantity']);
        $this->assertNull($never['calculated_quantity']);
        $this->assertContains('no_previous_count', $never['warnings']);
        $this->assertContains('unknown_conversion', $this->previewRow('sr.t.04')['warnings']);

        $this->assertNotContains($this->supplyItem('sr.t.06')->id, collect($this->getJson('/ems/api/supply/reconciliation')->json('data.rows'))->pluck('supply_item_id')->all(), 'Controlled substances are excluded from stock.');
        $this->assertSame(0, InventorySnapshot::query()->where('kind', 'reconciliation')->count(), 'A preview saves nothing.');
    }

    public function test_negative_results_and_unmatched_issuances_are_warned_about(): void
    {
        $this->finalizeCount($this->staff, ['sr.t.03' => 1, 'sr.t.01' => 100]);
        $member = $this->emsMember();
        $uuid = $this->submitRequest($member, [
            ['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => 2],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10],
        ])['uuid'];
        $this->actingAs($this->staff);
        $lines = collect($this->getJson("/ems/api/requests/{$uuid}")->json('data.lines'))->keyBy('item_key');
        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/issue", ['issuances' => [
            ['line_id' => $lines['airway.top.stethoscope']['id'], 'quantity' => 2],
            ['line_id' => $lines['als.trauma.gauze']['id'], 'quantity' => 10],
        ]])->assertOk();

        $stetho = $this->previewRow('sr.t.03');
        $this->assertSame(-1, $stetho['calculated_quantity']);
        $this->assertContains('negative_result', $stetho['warnings']);

        $this->assertContains('unmatched_issuances', $this->previewRow('sr.t.01')['warnings']);
        $unmatched = $this->getJson('/ems/api/supply/reconciliation')->json('data.unmatched_issuances');
        $this->assertCount(1, $unmatched);
        $this->assertSame(10, $unmatched[0]['quantity']);
        $this->assertSame('Gauze 4x4', $unmatched[0]['label']);
        $this->assertSame(100, $this->previewRow('sr.t.01')['calculated_quantity'], 'An unmatched issuance is reported, never guessed into a number.');
    }

    public function test_confirming_creates_one_calculated_snapshot_and_confirming_again_returns_it(): void
    {
        $this->finalizeCount($this->staff, ['sr.t.02' => 30]);
        $this->issueTape(4);

        $first = $this->confirmReconciliation('confirm-key-0001')->assertCreated();
        $second = $this->confirmReconciliation('confirm-key-0002')->assertOk();
        $third = $this->confirmReconciliation('confirm-key-0001')->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertTrue($second->json('data.unchanged'));
        $this->assertSame($first->json('data.id'), $third->json('data.id'));
        $this->assertSame(1, InventorySnapshot::query()->where('kind', 'reconciliation')->count());

        $snapshot = InventorySnapshot::query()->where('kind', 'reconciliation')->sole();
        $this->assertSame('final', $snapshot->status);
        $line = $snapshot->lines()->where('supply_item_id', $this->supplyItem('sr.t.02')->id)->sole();
        $this->assertSame(26, $line->quantity_base);
        $this->assertSame('calculated', $line->value_source, 'A calculated value is never recorded as a count.');
        $notCounted = $snapshot->lines()->where('supply_item_id', $this->supplyItem('sr.t.01')->id)->sole();
        $this->assertNull($notCounted->quantity_base);
        $this->assertSame('not_counted', $notCounted->value_source);
        $this->assertStringContainsString('Not a physical count', $snapshot->notes);

        $stock = $this->stockOf($this->staff, 'sr.t.02');
        $this->assertSame(26, $stock['current_quantity']);
        $this->assertSame('calculated', $stock['current_basis']);
    }

    public function test_consecutive_reconciliations_never_deduct_the_same_issuance_twice(): void
    {
        $this->finalizeCount($this->staff, ['sr.t.02' => 30]);
        $this->issueTape(4);
        $this->confirmReconciliation('confirm-key-1001')->assertCreated();

        $this->issueTape(2);
        $row = $this->previewRow('sr.t.02');
        $this->assertSame(26, $row['previous_quantity']);
        $this->assertSame(2, $row['issued'], 'Only what was issued since the last reconciliation.');
        $this->assertSame(24, $row['calculated_quantity']);

        $second = $this->confirmReconciliation('confirm-key-1002')->assertCreated();
        $this->assertSame(2, InventorySnapshot::query()->where('kind', 'reconciliation')->count());
        $this->assertSame(24, InventorySnapshotLine::query()->whereHas('snapshot', fn ($q) => $q->where('uuid', $second->json('data.id')))->where('supply_item_id', $this->supplyItem('sr.t.02')->id)->value('quantity_base'));
        $this->assertSame(24, $this->stockOf($this->staff, 'sr.t.02')['current_quantity']);
        $this->assertTrue($this->getJson('/ems/api/supply/reconciliation')->json('data.nothing_to_reconcile'));

        // A later physical count wins for the items it covers; movements after it still apply.
        $this->finalizeCount($this->staff, ['sr.t.02' => 20]);
        $this->issueTape(1);
        $this->assertSame(19, $this->stockOf($this->staff, 'sr.t.02')['current_quantity']);
        $this->assertStringStartsWith('Calculated since count', $this->stockOf($this->staff, 'sr.t.02')['current_basis_label']);
    }

    public function test_reorder_advisories_follow_the_threshold_and_never_create_orders(): void
    {
        $this->finalizeCount($this->staff, ['sr.t.02' => 10, 'sr.t.07' => 5, 'sr.t.01' => 80, 'sr.t.05' => 5]);

        $this->actingAs($this->staff);
        $advice = $this->getJson('/ems/api/supply/advisories')->assertOk()->json('data');
        $names = collect($advice['advisories'])->pluck('name')->all();
        sort($names);
        $this->assertSame(['Gauze 4x4 Sterile', 'Tape 1 inch'], $names, 'Below the threshold only: 80 of 50 and 5 of 5 are not advised.');

        $tape = collect($advice['advisories'])->firstWhere('name', 'Tape 1 inch');
        $this->assertSame(10, $tape['current_quantity']);
        $this->assertSame('counted', $tape['current_basis']);
        $this->assertSame(12, $tape['threshold_qty_base']);
        $this->assertSame('< 12U', $tape['threshold_source_text']);
        $this->assertSame(2, $tape['suggested_packages']);
        $this->assertSame('source_order_amount', $tape['suggested_basis']);
        $this->assertSame('box', $tape['package_unit']);
        $this->assertSame('BT', $tape['supplier_code']);
        $this->assertSame('100-sr.t.02', $tape['item_number']);
        $this->assertTrue($tape['details_unverified']);

        $this->assertSame(['Gloves Medium'], collect($advice['unknown_threshold'])->pluck('name')->all());
        $this->assertContains('Stethoscope', collect($advice['needs_count'])->pluck('name')->all());
        $this->assertNotContains('Fentanyl (Narcotics Locker)', collect($advice['advisories'])->pluck('name')->merge(collect($advice['unknown_threshold'])->pluck('name'))->merge(collect($advice['needs_count'])->pluck('name'))->all());
        $this->assertSame([], $this->getJson('/ems/api/supply/orders')->json('data'), 'A recommendation is not an order.');

        // Without a source order amount the shortfall is rounded up to whole packages when the size is explicit.
        $admin = $this->emsAdmin(['admin.ems.manage']);
        $this->actingAs($admin)->patchJson('/ems/api/supply/items/'.$this->supplyItem('sr.t.02')->id, ['order_qty_packages' => null, 'reason' => 'Order by shortfall'], ['Idempotency-Key' => 'item-edit-0001'])->assertOk();
        $tape = collect($this->actingAs($this->staff)->getJson('/ems/api/supply/advisories')->json('data.advisories'))->firstWhere('name', 'Tape 1 inch');
        $this->assertSame(1, $tape['suggested_packages']);
        $this->assertSame('rounded_up_to_packages', $tape['suggested_basis']);

        // Once a person records an order it shows beside the advisory.
        $this->emsPost($this->staff, '/ems/api/supply/orders', ['supply_item_id' => $this->supplyItem('sr.t.02')->id, 'packages' => 1])->assertCreated();
        $tape = collect($this->getJson('/ems/api/supply/advisories')->json('data.advisories'))->firstWhere('name', 'Tape 1 inch');
        $this->assertCount(1, $tape['open_orders']);
    }

    public function test_orders_are_placed_received_once_or_cancelled_and_unknown_pack_sizes_need_a_quantity(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $placed = $this->emsPost($this->staff, '/ems/api/supply/orders', ['supply_item_id' => $tape->id, 'packages' => 3, 'notes' => 'Phone order'])->assertCreated();
        $this->assertSame('placed', $placed->json('data.status'));

        $this->emsPost($this->staff, '/ems/api/supply/orders/'.$placed->json('data.id').'/receive')->assertOk();
        $movement = StockMovement::query()->sole();
        $this->assertSame('received', $movement->movement_type);
        $this->assertSame(72, $movement->quantity_base);
        $this->assertSame($placed->json('data.id'), $movement->supply_order_id);
        $this->emsPost($this->staff, '/ems/api/supply/orders/'.$placed->json('data.id').'/receive')->assertStatus(409)->assertJsonPath('error.code', 'order_not_open');
        $this->assertSame(1, StockMovement::query()->count());

        $gloves = $this->emsPost($this->staff, '/ems/api/supply/orders', ['supply_item_id' => $this->supplyItem('sr.t.04')->id, 'packages' => 1])->assertCreated();
        $this->assertNull($gloves->json('data.quantity_base'));
        $this->emsPost($this->staff, '/ems/api/supply/orders/'.$gloves->json('data.id').'/receive')->assertStatus(422)->assertJsonPath('error.code', 'quantity_needed');
        $this->emsPost($this->staff, '/ems/api/supply/orders/'.$gloves->json('data.id').'/receive', ['quantity_base' => 400])->assertOk();
        $this->assertSame(400, StockMovement::query()->latest('id')->first()->quantity_base);

        $cancelled = $this->emsPost($this->staff, '/ems/api/supply/orders', ['supply_item_id' => $tape->id, 'packages' => 1])->json('data.id');
        $this->emsPost($this->staff, "/ems/api/supply/orders/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->emsPost($this->staff, "/ems/api/supply/orders/{$cancelled}/receive")->assertStatus(409);
        $this->assertSame(2, StockMovement::query()->count());

        $this->emsPost($this->staff, '/ems/api/supply/orders', ['supply_item_id' => $this->supplyItem('sr.t.06')->id, 'packages' => 1])->assertStatus(422)->assertJsonPath('error.code', 'controlled_not_tracked');
        $this->emsPost($this->staff, '/ems/api/supply/adjustments', ['supply_item_id' => $this->supplyItem('sr.t.06')->id, 'quantity_base' => 1, 'reason' => 'nope'])->assertStatus(422);
        $this->emsPost($this->staff, '/ems/api/supply/adjustments', ['supply_item_id' => $tape->id, 'quantity_base' => 0, 'reason' => 'zero'])->assertStatus(422);
        $this->emsPost($this->staff, '/ems/api/supply/adjustments', ['supply_item_id' => $tape->id, 'quantity_base' => 3])->assertStatus(422)->assertJsonPath('error.message', 'Give a reason for the adjustment.');
    }

    public function test_thresholds_and_packaging_are_edited_with_an_audit_trail_by_authorized_people_only(): void
    {
        $gloves = $this->supplyItem('sr.t.04');
        $admin = $this->emsAdmin(['admin.ems.manage']);
        $uri = '/ems/api/supply/items/'.$gloves->id;

        // A Captain can act but cannot reconfigure the catalogue.
        $this->actingAs($this->staff)->patchJson($uri, ['threshold_qty_base' => 40, 'reason' => 'Counted usage'], ['Idempotency-Key' => 'item-edit-1001'])->assertForbidden();

        $this->actingAs($admin)->patchJson($uri, ['threshold_qty_base' => 40], ['Idempotency-Key' => 'item-edit-1002'])->assertStatus(422);
        $this->actingAs($admin)->patchJson($uri, ['reason' => 'Nothing else'], ['Idempotency-Key' => 'item-edit-1003'])->assertStatus(422)->assertJsonPath('error.code', 'no_change');
        $response = $this->actingAs($admin)->patchJson($uri, [
            'threshold_qty_base' => 40, 'units_per_package' => 10, 'package_unit' => 'box', 'location_code' => 'A2', 'resolve_review' => true, 'reason' => 'Counted a case on the shelf',
        ], ['Idempotency-Key' => 'item-edit-1004'])->assertOk();

        $item = $gloves->fresh();
        $this->assertSame(40, $item->threshold_qty_base);
        $this->assertSame(10, $item->units_per_package);
        $this->assertFalse($item->needs_review);
        $this->assertSame('A2', $item->location->code);
        $this->assertEqualsCanonicalizing(['threshold_qty_base', 'units_per_package', 'package_unit', 'location_id', 'needs_review', 'review_reasons'], $response->json('data.changed_fields'));
        $audit = collect($response->json('data.audit'));
        $threshold = $audit->firstWhere('field', 'threshold_qty_base');
        $this->assertNull($threshold['before']);
        $this->assertSame(40, $threshold['after']);
        $this->assertSame('Counted a case on the shelf', $threshold['reason']);
        $this->assertNotNull($threshold['actor_name']);

        $this->actingAs($admin)->patchJson($uri, ['package_unit' => 'barrel', 'reason' => 'bad unit'], ['Idempotency-Key' => 'item-edit-1005'])->assertStatus(422);
        $this->actingAs($admin)->patchJson($uri, ['location_code' => 'NOPE', 'reason' => 'bad place'], ['Idempotency-Key' => 'item-edit-1006'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_location');
    }

    public function test_movements_and_snapshots_can_be_listed_and_read(): void
    {
        $countUuid = $this->finalizeCount($this->staff, ['sr.t.02' => 30]);
        $this->issueTape(4);

        $this->actingAs($this->staff);
        $movements = $this->getJson('/ems/api/supply/movements?per_page=10')->assertOk();
        $this->assertSame(1, $movements->json('meta.total'));
        $this->assertSame(-4, $movements->json('data.0.quantity_base'));
        $this->assertSame('Tape 1 inch', $movements->json('data.0.item_name'));
        $this->assertCount(0, $this->getJson('/ems/api/supply/movements?type=received')->json('data'));

        $snapshots = $this->getJson('/ems/api/supply/snapshots?kind=physical_count')->assertOk();
        $this->assertSame([$countUuid], collect($snapshots->json('data'))->pluck('id')->all());
        $detail = $this->getJson("/ems/api/supply/snapshots/{$countUuid}")->assertOk();
        $this->assertSame(30, $detail->json('data.lines.0.quantity_base'));
        $this->assertSame('counted', $detail->json('data.lines.0.value_source'));
        $this->getJson('/ems/api/supply/counts')->assertOk()->assertJsonCount(1, 'data');
    }
}
