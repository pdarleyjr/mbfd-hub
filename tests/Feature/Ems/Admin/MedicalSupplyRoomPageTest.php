<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Admin\Pages\MedicalSupplyRoom;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItemEvent;
use App\Models\Ems\SupplyOrder;
use App\Models\User;
use App\Services\Ems\EmsException;
use App\Services\Ems\SupplyItemMaintenance;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class MedicalSupplyRoomPageTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $manager;

    private User $viewer;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->manager = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
        $this->viewer = $this->emsAdmin(['admin.ems.view'], 'Vera Viewer');
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function actAs(User $user): void
    {
        $this->forgetEmsMemo();
        $this->actingAs($user->fresh());
    }

    private function page(string $tab = 'stock'): Testable
    {
        return Livewire::test(MedicalSupplyRoom::class)->set('tab', $tab);
    }

    private function issueTape(int $quantity): void
    {
        $uuid = $this->submitRequest($this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => $quantity]])['uuid'];
        $this->actAs($this->manager);
        $line = $this->getJson("/ems/api/requests/{$uuid}")->json('data.lines.0');
        $this->emsPost($this->manager, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $line['id'], 'quantity' => $quantity]]])->assertOk();
    }

    // -------------------------------------------------------------------- stock

    public function test_the_stock_tab_lists_tracked_items_with_flags_and_keeps_controlled_items_separate(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.02' => 10, 'sr.t.01' => 80]);
        $this->actAs($this->manager);
        $tape = $this->supplyItem('sr.t.02');
        $gloves = $this->supplyItem('sr.t.04');
        $cardizem = $this->supplyItem('sr.t.05');
        $fentanyl = $this->supplyItem('sr.t.06');

        $this->page()
            ->assertCanSeeTableRecords([$tape, $gloves, $cardizem])
            ->assertCanNotSeeTableRecords([$fentanyl])
            ->assertSee('Tracked through the controlled-substance procedure')->assertSee('Fentanyl (Narcotics Locker)')
            ->assertSee('Counted')->assertSee('Below threshold')->assertSee('Original: &lt; 12U', false)
            ->assertSee('Refrigerated')->assertSee('Needs review')
            ->filterTable('below_threshold', true)->assertCanSeeTableRecords([$tape])->assertCanNotSeeTableRecords([$gloves, $cardizem])
            ->filterTable('below_threshold', false)->filterTable('needs_review', true)->assertCanSeeTableRecords([$gloves])->assertCanNotSeeTableRecords([$tape])
            ->filterTable('needs_review', false)->filterTable('location', 'D1')->assertCanSeeTableRecords([$cardizem])->assertCanNotSeeTableRecords([$tape])
            ->removeTableFilters()->searchTable('stetho')->assertCanSeeTableRecords([$this->supplyItem('sr.t.03')])->assertCanNotSeeTableRecords([$tape]);
    }

    public function test_item_settings_show_provenance_and_only_a_manager_can_change_them(): void
    {
        $gloves = $this->supplyItem('sr.t.04');

        $this->actAs($this->viewer);
        $this->page()->mountTableAction('settings', $gloves)->assertSee('Where this row came from')->assertSee('page 1, row 1')->assertSee('BT 1.00EA 6/22')->assertSee('unverified');
        $this->page()->mountTableAction('settings', $gloves)->setTableActionData(['threshold_qty_base' => 40, 'reason' => 'I should not be able to'])->call('callMountedTableAction');
        $this->assertNull($gloves->fresh()->threshold_qty_base);
        $this->assertSame(0, SupplyItemEvent::query()->count());
        $this->expectRefused(fn () => app(SupplyItemMaintenance::class)->update($gloves->id, $this->viewer->fresh(), 'viewer-edit-0001', ['threshold_qty_base' => 40, 'reason' => 'Viewer edit']));

        // A Captain can count and order but cannot reconfigure the catalogue.
        $this->actAs($this->emsCaptain());
        $this->page()->mountTableAction('settings', $gloves)->setTableActionData(['threshold_qty_base' => 40, 'reason' => 'Captain edit'])->call('callMountedTableAction');
        $this->assertNull($gloves->fresh()->threshold_qty_base);
        $this->assertSame(0, SupplyItemEvent::query()->count());

        $this->actAs($this->manager);
        $this->page()->callTableAction('settings', $gloves, [
            'threshold_qty_base' => 40, 'units_per_package' => 10, 'package_unit' => 'box', 'location_code' => 'A2', 'resolve_review' => true,
            'reason' => 'Counted a case on the shelf',
        ])->assertHasNoTableActionErrors();
        $item = $gloves->fresh();
        $this->assertSame(40, $item->threshold_qty_base);
        $this->assertSame(10, $item->units_per_package);
        $this->assertFalse($item->needs_review);
        $this->assertSame('A2', $item->location->code);
        $audit = SupplyItemEvent::query()->where('supply_item_id', $item->id)->where('field', 'threshold_qty_base')->sole();
        $this->assertSame('Counted a case on the shelf', $audit->reason);
        $this->assertSame($this->manager->id, $audit->user_id);

        $this->page()->callTableAction('settings', $gloves, ['threshold_qty_base' => 40, 'reason' => 'Nothing differs'])->assertNotified('Nothing was changed');
    }

    // ------------------------------------------------------- count -> reconcile -> order

    public function test_a_count_is_started_saved_resumed_finalized_and_becomes_the_stock_basis(): void
    {
        $this->actAs($this->manager);
        $tape = $this->supplyItem('sr.t.02');
        $gauze = $this->supplyItem('sr.t.01');

        $page = $this->page('counts')->assertSee('No count is in progress')
            ->callAction('startCount', ['locations' => ['A2']])->assertHasNoActionErrors();
        $draft = InventorySnapshot::query()->where('status', 'draft')->sole();
        $this->assertSame('A2', $draft->scope_location_code);
        $this->assertSame($draft->id, $page->get('countId'));
        $page->assertSee('Counting: A2')->assertSee('Tape 1 inch')->assertSee('Gauze 4x4');
        $this->assertArrayHasKey($tape->id, $page->get('entries'));

        $page->set("entries.{$tape->id}.boxes", '2')->set("entries.{$tape->id}.each", '5')
            ->set("entries.{$gauze->id}.cases", '1')
            ->callAction('saveCount')->assertHasNoActionErrors();
        $lines = InventorySnapshotLine::query()->get()->keyBy('supply_item_id');
        $this->assertSame(53, $lines[$tape->id]->quantity_base, '2 boxes of 24 and 5 each.');
        $this->assertNull($lines[$gauze->id]->quantity_base, 'Cases with no stated size are not guessed.');
        $page->assertSee('= 53 units');

        // Leave and come back: the draft resumes with what was typed.
        $resumed = $this->page('counts')->assertSee('A2')->call('openCount', $draft->id);
        $this->assertSame('2', $resumed->get("entries.{$tape->id}.boxes"));
        $this->assertSame('5', $resumed->get("entries.{$tape->id}.each"));

        $resumed->callAction('finalizeCount')->assertHasNoActionErrors();
        $final = InventorySnapshot::query()->sole();
        $this->assertSame('final', $final->status);
        $this->assertSame(1, $final->version);
        $this->assertSame('Manny Manager', $final->finalized_name);
        $this->assertNull($resumed->get('countId'));

        $this->page()->assertSee('Counted')->assertSee('53 each');
    }

    public function test_finalizing_an_empty_count_is_refused_in_plain_words_and_a_draft_can_be_thrown_away(): void
    {
        $this->actAs($this->manager);
        $page = $this->page('counts')->callAction('startCount', ['locations' => ['__all']]);
        $this->assertSame(1, InventorySnapshot::query()->where('status', 'draft')->count());
        $page->callAction('finalizeCount')->assertNotified('Nothing was changed');
        $this->assertSame(0, InventorySnapshot::query()->where('status', 'final')->count());
        $this->page('counts')->call('openCount', InventorySnapshot::query()->value('id'))->callAction('discardCount');
        $this->assertSame(0, InventorySnapshot::query()->count());
    }

    public function test_reconcile_previews_then_confirms_once_and_never_counts_a_movement_twice(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.02' => 30]);
        $this->issueTape(4);
        $this->actAs($this->manager);

        $page = $this->page('reconcile')->assertSee('Tape 1 inch')->assertSee('have no earlier count and stay "not counted"', false)->assertSee('unchanged items hidden');
        $this->assertSame(StockMovement::query()->max('id'), $page->get('previewLastMovementId'));
        $this->assertSame(0, InventorySnapshot::query()->where('kind', 'reconciliation')->count(), 'A preview saves nothing.');

        $page->callAction('confirmReconciliation')->assertHasNoActionErrors();
        $page->callAction('confirmReconciliation');
        $this->assertSame(1, InventorySnapshot::query()->where('kind', 'reconciliation')->count());
        $snapshot = InventorySnapshot::query()->where('kind', 'reconciliation')->sole();
        $this->assertSame(26, $snapshot->lines()->where('supply_item_id', $this->supplyItem('sr.t.02')->id)->value('quantity_base'));
        $this->assertSame('calculated', $snapshot->lines()->where('supply_item_id', $this->supplyItem('sr.t.02')->id)->value('value_source'));

        $this->page('reconcile')->assertSee('Nothing new to reconcile');
        $this->page('stock')->assertSee('Calculated since count');
    }

    public function test_reorder_advice_orders_and_receipts_keep_a_recommendation_apart_from_an_order(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.02' => 10, 'sr.t.01' => 80]);
        $this->actAs($this->manager);
        $tape = $this->supplyItem('sr.t.02');

        $page = $this->page('reorder')->assertSee('Reorder recommended')->assertSee('Tape 1 inch')->assertSee('Historical, unverified', false);
        $this->assertSame(0, SupplyOrder::query()->count(), 'A recommendation is not an order.');

        $page->callAction('recordOrder', ['packages' => 2, 'supplier_code' => 'BT', 'item_number' => '100-sr.t.02'], ['item' => $tape->id])->assertHasNoActionErrors();
        $order = SupplyOrder::query()->sole();
        $this->assertSame('placed', $order->status);
        $this->assertSame(2, $order->packages);
        $this->assertSame(48, $order->quantity_base);
        $this->assertSame(0, StockMovement::query()->count(), 'Placing an order does not change stock.');
        $page->assertSee('Order placed');

        $page->callAction('receiveOrder', [], ['order' => $order->id])->assertHasNoActionErrors();
        $this->assertSame('received', $order->fresh()->status);
        $movement = StockMovement::query()->sole();
        $this->assertSame(48, $movement->quantity_base);
        $this->assertSame('received', $movement->movement_type);
        $this->assertSame($order->id, $movement->supply_order_id);
        $page->callAction('receiveOrder', [], ['order' => $order->id])->assertNotified('Nothing was changed');
        $this->assertSame(1, StockMovement::query()->count());
        $page = $this->page('reorder'); // a refused action leaves its modal open, so start clean

        $second = SupplyOrder::query()->create(['supply_item_id' => $tape->id, 'status' => 'placed', 'packages' => 1, 'quantity_base' => 24, 'placed_at' => now(), 'idempotency_key' => 'place:test:cancel']);
        $page->callAction('cancelOrder', [], ['order' => $second->id]);
        $this->assertSame('cancelled', $second->fresh()->status);

        // An item with an unknown package size asks how many units arrived.
        $gloves = SupplyOrder::query()->create(['supply_item_id' => $this->supplyItem('sr.t.04')->id, 'status' => 'placed', 'packages' => 1, 'placed_at' => now(), 'idempotency_key' => 'place:test:gloves']);
        $page->callAction('receiveOrder', [], ['order' => $gloves->id])->assertHasActionErrors(['quantity_base' => 'required']);
        $page = $this->page('reorder');
        $page->callAction('receiveOrder', ['quantity_base' => 400], ['order' => $gloves->id])->assertHasNoActionErrors();
        $this->assertSame(400, StockMovement::query()->latest('id')->first()->quantity_base);
    }

    public function test_movements_and_snapshots_are_listed_and_a_snapshot_exports(): void
    {
        $count = $this->finalizeCount($this->manager, ['sr.t.02' => 30]);
        $this->issueTape(4);
        $this->actAs($this->manager);

        $this->page('movements')->assertSee('Tape 1 inch')->assertSee('-4')->assertSee('Issued')->assertSee('MIR-');
        $this->page('movements')->set('movementType', 'received')->assertSee('No stock movements yet');

        $page = $this->page('snapshots')->assertSee('Physical count')->call('viewSnapshot', $count)->assertSee('Tape 1 inch')->assertSee('Counted');
        $page->callAction('exportSnapshot', [], ['snapshot' => $count, 'format' => 'xlsx'])->assertFileDownloaded();
        $page->callAction('exportSnapshot', [], ['snapshot' => $count, 'format' => 'pdf'])->assertFileDownloaded();
    }

    // ------------------------------------------------------------------- access

    public function test_a_read_only_viewer_can_read_every_tab_but_has_no_actions_and_the_server_refuses_them(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.02' => 10]);
        $this->actAs($this->viewer);
        $tape = $this->supplyItem('sr.t.02');

        foreach (array_keys(MedicalSupplyRoom::TABS) as $tab) {
            $this->page($tab)->assertOk();
        }
        $this->page('counts')->assertActionHidden('startCount');
        $this->page('reconcile')->assertActionHidden('confirmReconciliation');

        $before = [InventorySnapshot::query()->count(), SupplyOrder::query()->count(), StockMovement::query()->count()];
        $this->page('counts')->call('mountAction', 'startCount')->call('callMountedAction');
        $this->page('reorder')->call('mountAction', 'recordOrder', ['item' => $tape->id])->call('callMountedAction');
        $this->page('reconcile')->call('mountAction', 'confirmReconciliation')->call('callMountedAction');
        $this->assertSame($before, [InventorySnapshot::query()->count(), SupplyOrder::query()->count(), StockMovement::query()->count()]);
    }

    public function test_anyone_without_ems_access_gets_a_403_on_the_page_and_on_every_interaction(): void
    {
        $plainAdmin = $this->emsAdmin([], 'Plain Admin');
        foreach ([$plainAdmin, $this->member] as $user) {
            $this->actAs($user);
            $this->assertFalse(MedicalSupplyRoom::canAccess());
            Livewire::test(MedicalSupplyRoom::class)->assertForbidden();
        }

        $this->actAs($this->manager);
        $page = $this->page();
        $this->actAs($this->viewer);
        $this->assertTrue(MedicalSupplyRoom::canAccess());
        $this->emsAdminLoses('admin.ems.view', $this->viewer);
        $page->call('setTab', 'counts')->assertForbidden();
    }

    private function expectRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('The service accepted a change from someone who may not make it.');
        } catch (EmsException $exception) {
            $this->assertSame(403, $exception->status);
        }
    }

    private function emsAdminLoses(string $permission, User $user): void
    {
        $user->revokePermissionTo($permission);
        $this->actAs($user);
    }
}
