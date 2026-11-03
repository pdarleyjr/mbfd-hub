<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use App\Models\Ems\SupplyOrder;
use App\Models\Ems\SupplyOrderEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Edit mode, expected on hand and the order workflow on the master inventory
 * (docs/ems/captain5-medical-inventory-workflow.md sections 6 and 11).
 * Fixture rows: sr.t.01 Gauze 4x4 (box of 200, A2), sr.t.02 Tape 1 inch (box of 24, A2), sr.t.03 Stethoscope (each, B1).
 */
final class SupplyRoomOrdersTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $captain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->captain = $this->emsCaptain();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @param array<string, mixed> $payload */
    private function send(User $user, string $uri, array $payload): TestResponse
    {
        return $this->actingAs($user)->patchJson($uri, $payload, ['Idempotency-Key' => 'k-'.Str::random(16)]);
    }

    /** @return array<string, mixed> */
    private function row(int $itemId, ?User $user = null): array
    {
        return collect($this->actingAs($user ?? $this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data.items'))->firstWhere('id', $itemId);
    }

    /** Stethoscope: 2 on hand, 5 expected. */
    private function shortStethoscope(): SupplyItem
    {
        $item = $this->supplyItem('sr.t.03');
        $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [['id' => $item->id, 'expected' => 5, 'on_hand' => 2]]])->assertOk();

        return $item;
    }

    public function test_the_list_shows_expected_to_order_and_a_summary(): void
    {
        $item = $this->shortStethoscope();

        $data = $this->actingAs($this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data');
        $row = collect($data['items'])->firstWhere('id', $item->id);
        $this->assertSame(5, $row['expected']);
        $this->assertSame(3, $row['to_order']);
        $this->assertSame('needs_order', $row['order_state']);
        $this->assertNull($row['open_order']);
        $this->assertSame(1, $data['summary']['needs_order']);
        $this->assertContains('bottle', array_column($data['units'], 'value'));
        $this->assertSame('not_counted', collect($data['items'])->firstWhere('id', $this->supplyItem('sr.t.01')->id)['order_state']);
    }

    public function test_one_edit_save_changes_several_items_and_every_field_is_kept_in_history(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $stethoscope = $this->supplyItem('sr.t.03');

        $items = $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [
            ['id' => $tape->id, 'name' => 'Tape 1 inch silk', 'new_location_label' => 'Capt 5 Office', 'count_unit' => 'roll', 'units_per_package' => 1, 'expected' => 10, 'on_hand' => 4],
            ['id' => $stethoscope->id, 'location_code' => 'A2', 'expected' => 2],
        ]])->assertOk()->json('data.items');

        $this->assertSame([$tape->id, $stethoscope->id], array_column($items, 'id'));
        $this->assertSame('Tape 1 inch silk', $items[0]['name']);
        $this->assertSame('CAPT 5 OFFICE', $items[0]['location_code']);
        $this->assertSame('roll', $items[0]['count_unit']);
        $this->assertSame('rolls', $items[0]['count_unit_label_plural']);
        $this->assertSame(4, $items[0]['on_hand']);
        $this->assertSame(10, $items[0]['expected']);
        $this->assertSame(6, $items[0]['to_order']);
        $this->assertSame('A2', $items[1]['location_code']);
        $this->assertSame(['name', 'location_id', 'count_unit', 'package_unit', 'units_per_package', 'threshold_qty_base', 'threshold_source_text'],
            array_values(array_intersect(['name', 'location_id', 'count_unit', 'package_unit', 'units_per_package', 'threshold_qty_base', 'threshold_source_text'], SupplyItemEvent::query()->where('supply_item_id', $tape->id)->pluck('field')->all())));
    }

    public function test_a_refused_row_saves_nothing_and_names_the_row_and_field(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $stethoscope = $this->supplyItem('sr.t.03');

        $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [
            ['id' => $stethoscope->id, 'name' => 'Stethoscope Adult'],
            ['id' => $tape->id, 'count_unit' => 'bottle', 'units_per_package' => null],
        ]])->assertStatus(422)->assertJsonPath('error.fields', fn ($fields): bool => array_key_exists('changes.1.units_per_package', (array) $fields));

        $this->assertSame('Stethoscope', $stethoscope->fresh()->name, 'All or nothing.');
        $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [['id' => $stethoscope->id, 'expected' => -1]]])
            ->assertStatus(422)->assertJsonPath('error.fields', fn ($fields): bool => array_key_exists('changes.0.expected', (array) $fields));
    }

    public function test_the_whole_order_workflow_ends_with_no_flags(): void
    {
        $item = $this->shortStethoscope();

        $row = $this->emsPost($this->captain, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'pending', 'quantity' => 3])->assertCreated()->json('data');
        $this->assertSame('pending', $row['order_state']);
        $this->assertSame(3, $row['open_order']['quantity']);
        $orderId = $row['open_order']['id'];
        $this->emsPost($this->captain, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'ordered', 'quantity' => 3])->assertStatus(409)->assertJsonPath('error.code', 'order_open');

        $this->send($this->captain, "/ems/api/supply/orders/{$orderId}", ['status' => 'ordered'])->assertOk()->assertJsonPath('data.order_state', 'ordered');
        $row = $this->send($this->captain, "/ems/api/supply/orders/{$orderId}", ['status' => 'backordered', 'expected_on' => now()->addWeek()->toDateString(), 'note' => 'Supplier says two weeks'])->assertOk()->json('data');
        $this->assertSame('backordered', $row['order_state']);
        $this->assertSame('Supplier says two weeks', $row['open_order']['note']);
        $this->assertSame(1, $this->actingAs($this->captain)->getJson('/ems/api/supply/master')->json('data.summary.backordered'));

        // Part of it arrives: stock goes up, the order closes, and the rest shows as needed again.
        $row = $this->emsPost($this->captain, "/ems/api/supply/orders/{$orderId}/arrived", ['quantity' => 2])->assertOk()->json('data');
        $this->assertSame(4, $row['on_hand']);
        $this->assertSame('needs_order', $row['order_state']);
        $this->assertSame(1, $row['to_order']);
        $this->assertSame(2, (int) StockMovement::query()->where('supply_order_id', $orderId)->value('quantity_base'));
        $this->emsPost($this->captain, "/ems/api/supply/orders/{$orderId}/arrived", ['quantity' => 2])->assertStatus(409)->assertJsonPath('error.code', 'order_not_open');

        $second = $this->emsPost($this->captain, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'ordered', 'quantity' => 1])->assertCreated()->json('data.open_order.id');
        $row = $this->emsPost($this->captain, "/ems/api/supply/orders/{$second}/arrived", ['quantity' => 1])->assertOk()->json('data');
        $this->assertSame(5, $row['on_hand']);
        $this->assertSame('ok', $row['order_state']);
        $this->assertSame(0, $row['to_order']);
        $this->assertSame(['needs_order' => 0, 'pending' => 0, 'ordered' => 0, 'backordered' => 0, 'red' => 0, 'yellow' => 0, 'green' => 1], $this->actingAs($this->captain)->getJson('/ems/api/supply/master')->json('data.summary'));

        $this->assertSame(['pending', 'placed', 'backordered', 'received'], SupplyOrderEvent::query()->where('supply_order_id', $orderId)->orderBy('id')->pluck('to_status')->all());
        $this->assertSame(0, SupplyOrder::query()->whereIn('status', SupplyOrder::OPEN)->count());
    }

    public function test_cancelling_brings_the_flag_back_and_a_monday_count_moves_the_numbers(): void
    {
        $item = $this->shortStethoscope();
        $orderId = $this->emsPost($this->captain, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'ordered', 'quantity' => 3])->json('data.open_order.id');
        $this->send($this->captain, "/ems/api/supply/orders/{$orderId}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.order_state', 'needs_order');

        // A Monday count with every other item left blank, and nothing else sent: no field is mandatory.
        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($this->captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $item->id, 'on_hand' => 6]]], ['Idempotency-Key' => 'e-'.Str::random(12)])->assertOk();
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit")->assertOk();

        $row = $this->row($item->id);
        $this->assertSame(6, $row['on_hand']);
        $this->assertSame('ok', $row['order_state']);
    }

    public function test_a_new_kind_of_package_counts_and_orders_in_its_own_unit(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [['id' => $tape->id, 'count_unit' => 'carton', 'units_per_package' => 12, 'expected' => 3, 'on_hand' => 1]]])->assertOk();

        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($this->captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $tape->id, 'on_hand' => 2]]], ['Idempotency-Key' => 'e-'.Str::random(12)])->assertOk();
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit")->assertOk();
        $row = $this->row($tape->id);
        $this->assertSame(2, $row['on_hand']);
        $this->assertSame(24, $row['on_hand_base']);
        $this->assertSame(1, $row['to_order']);

        $row = $this->emsPost($this->captain, "/ems/api/supply/master/items/{$tape->id}/order", ['status' => 'ordered', 'quantity' => 1])->assertCreated()->json('data');
        $this->assertSame('carton', $row['open_order']['unit_label']);
        $this->assertSame(12, $row['open_order']['quantity_base']);
    }

    public function test_the_older_admin_order_path_never_opens_a_second_order(): void
    {
        $item = $this->shortStethoscope();
        $this->emsPost($this->captain, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'pending', 'quantity' => 3])->assertCreated();

        $this->emsPost($this->emsAdmin(['admin.ems.manage']), '/ems/api/supply/orders', ['supply_item_id' => $item->id, 'packages' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'order_open');
        $this->assertSame(1, SupplyOrder::query()->where('supply_item_id', $item->id)->count());
    }

    public function test_an_open_order_follows_a_change_of_pack_size_so_arrival_adds_the_right_amount(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [['id' => $tape->id, 'count_unit' => 'box', 'units_per_package' => 24, 'expected' => 5, 'on_hand' => 1]]])->assertOk();
        $orderId = $this->emsPost($this->captain, "/ems/api/supply/master/items/{$tape->id}/order", ['status' => 'ordered', 'quantity' => 3])->json('data.open_order.id');
        $this->assertSame(72, SupplyOrder::query()->find($orderId)->quantity_base);

        $row = $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [['id' => $tape->id, 'units_per_package' => 12]]])->assertOk()->json('data.items.0');
        $this->assertSame(3, $row['open_order']['quantity']);
        $this->assertSame(36, $row['open_order']['quantity_base']);

        $this->send($this->captain, '/ems/api/supply/master/items', ['changes' => [['id' => $tape->id, 'count_unit' => 'each']]])->assertOk();
        $order = SupplyOrder::query()->find($orderId);
        $this->assertSame(['each', 36, 36], [$order->order_unit, $order->packages, $order->quantity_base]);

        // The admin Reorder tab's "Mark arrived" defaults to the stored amount, which is now right.
        $this->emsPost($this->emsAdmin(['admin.ems.manage']), "/ems/api/supply/orders/{$orderId}/receive")->assertOk();
        $this->assertSame(36, (int) StockMovement::query()->where('supply_order_id', $orderId)->value('quantity_base'));
    }

    public function test_an_order_can_still_be_cancelled_after_its_item_is_removed_and_a_partial_arrival_records_what_came(): void
    {
        $item = $this->shortStethoscope();
        $orderId = $this->emsPost($this->captain, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'ordered', 'quantity' => 3])->json('data.open_order.id');
        $this->emsPost($this->captain, "/ems/api/supply/orders/{$orderId}/arrived", ['quantity' => 1])->assertOk();
        $this->assertSame([1, 1], [SupplyOrder::query()->find($orderId)->packages, SupplyOrder::query()->find($orderId)->quantity_base]);
        $this->assertSame(3, SupplyOrderEvent::query()->where('supply_order_id', $orderId)->orderBy('id')->value('quantity_base'), 'What was ordered stays in the history.');

        $second = $this->emsPost($this->captain, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'ordered', 'quantity' => 2])->json('data.open_order.id');
        $this->send($this->captain, "/ems/api/supply/master/items/{$item->id}", ['active' => false])->assertOk();
        $this->send($this->captain, "/ems/api/supply/orders/{$second}", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(SupplyOrder::CANCELLED, SupplyOrder::query()->find($second)->status);
    }

    public function test_the_admin_page_opens_for_administrators_and_captain_5_only(): void
    {
        if (! is_file(public_path('daily/index.html'))) {
            $this->markTestSkipped('The Daily Checkout build is not present.');
        }
        foreach ([$this->captain, $this->emsAdmin([])] as $user) {
            $this->actingAs($user)->get('/daily/supply-room')->assertOk();
        }
        $this->actingAs($this->emsMember())->get('/daily/supply-room')->assertForbidden();
    }

    public function test_any_administrator_orders_and_edits_but_a_read_only_grant_and_a_member_cannot(): void
    {
        $item = $this->shortStethoscope();
        $admin = $this->emsAdmin([]);
        $orderId = $this->emsPost($admin, "/ems/api/supply/master/items/{$item->id}/order", ['status' => 'ordered', 'quantity' => 3])->assertCreated()->json('data.open_order.id');
        $this->assertSame($admin->id, (int) SupplyOrder::query()->find($orderId)->placed_by_user_id);
        $this->send($admin, '/ems/api/supply/master/items', ['changes' => [['id' => $item->id, 'expected' => 4]]])->assertOk();

        foreach ([$this->emsAdmin(['admin.ems.view']), $this->emsMember()] as $user) {
            $this->emsPost($user, "/ems/api/supply/orders/{$orderId}/arrived", ['quantity' => 3])->assertForbidden();
            $this->send($user, "/ems/api/supply/orders/{$orderId}", ['status' => 'backordered'])->assertForbidden();
            $this->send($user, '/ems/api/supply/master/items', ['changes' => [['id' => $item->id, 'expected' => 9]]])->assertForbidden();
        }
        $this->assertSame(SupplyOrder::PLACED, SupplyOrder::query()->find($orderId)->status);
    }
}
