<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\StockMovement;
use App\Models\User;
use App\Services\Ems\EmsActor;
use App\Services\Ems\EmsException;
use App\Services\Ems\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** Review fixes: baselines per count line, stale previews, movement keys, limits, history keys, frozen columns. */
final class EmsDataIntegrityTest extends TestCase
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

    /** @return array{uuid: string, line: array<string, mixed>} */
    private function tapeRequest(int $quantity): array
    {
        $uuid = $this->submitRequest($this->emsMember(), [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => $quantity]])['uuid'];

        return ['uuid' => $uuid, 'line' => $this->actingAs($this->staff)->getJson("/ems/api/requests/{$uuid}")->json('data.lines.0')];
    }

    private function issueTape(int $quantity): array
    {
        $request = $this->tapeRequest($quantity);
        $this->emsPost($this->staff, "/ems/api/requests/{$request['uuid']}/issue", ['issuances' => [['line_id' => $request['line']['id'], 'quantity' => $quantity]]])->assertOk();

        return $request;
    }

    private function saveCount(string $countUuid, int $each): void
    {
        $this->actingAs($this->staff)->putJson("/ems/api/supply/counts/{$countUuid}/lines", ['lines' => [['supply_item_id' => $this->supplyItem('sr.t.02')->id, 'each' => $each]]], ['Idempotency-Key' => 'save-'.Str::random(16)])->assertOk();
    }

    // ------------------------------------------------------------ H1

    public function test_issuances_made_while_a_count_draft_is_open_are_not_lost_when_it_is_finalized(): void
    {
        $count = $this->emsPost($this->staff, '/ems/api/supply/counts', [])->json('data.id');
        $this->saveCount($count, 10);                  // Monday: ten on the shelf
        $this->issueTape(2);                           // Tuesday: two leave the room
        $this->emsPost($this->staff, "/ems/api/supply/counts/{$count}/finalize")->assertOk(); // Friday

        $stock = $this->stockOf($this->staff, 'sr.t.02');
        $this->assertSame(8, $stock['current_quantity']);
        $this->assertSame('calculated', $stock['current_basis']);
        $line = DB::table('ems_inventory_snapshot_lines')->whereNotNull('quantity_base')->sole();
        $this->assertSame(0, (int) $line->through_movement_id, 'The line remembers it was typed before movement 1.');
        $this->assertSame(1, (int) DB::table('ems_inventory_snapshots')->value('through_movement_id'), 'The snapshot itself was finalized after it.');
    }

    public function test_an_unchanged_autosave_keeps_its_baseline_but_a_retyped_number_moves_it(): void
    {
        $count = $this->emsPost($this->staff, '/ems/api/supply/counts', [])->json('data.id');
        $this->saveCount($count, 10);
        $this->issueTape(2);
        $this->saveCount($count, 10);                  // the screen re-sends the whole draft
        $this->emsPost($this->staff, "/ems/api/supply/counts/{$count}/finalize")->assertOk();
        $this->assertSame(8, $this->stockOf($this->staff, 'sr.t.02')['current_quantity']);

        $second = $this->emsPost($this->staff, '/ems/api/supply/counts', [])->json('data.id');
        $this->saveCount($second, 7);                  // counted the shelf again after the issuance: 7 really are there
        $this->emsPost($this->staff, "/ems/api/supply/counts/{$second}/finalize")->assertOk();
        $this->assertSame(7, $this->stockOf($this->staff, 'sr.t.02')['current_quantity']);

        $this->issueTape(1);
        $this->assertSame(6, $this->stockOf($this->staff, 'sr.t.02')['current_quantity']);
    }

    public function test_a_reconciliation_after_such_a_count_uses_the_line_baseline(): void
    {
        $count = $this->emsPost($this->staff, '/ems/api/supply/counts', [])->json('data.id');
        $this->saveCount($count, 10);
        $this->issueTape(2);
        $this->emsPost($this->staff, "/ems/api/supply/counts/{$count}/finalize")->assertOk();

        $preview = $this->actingAs($this->staff)->getJson('/ems/api/supply/reconciliation')->json('data');
        $row = collect($preview['rows'])->firstWhere('supply_item_id', $this->supplyItem('sr.t.02')->id);
        $this->assertSame([10, 2, 8], [$row['previous_quantity'], $row['issued'], $row['calculated_quantity']]);
        $this->assertFalse($preview['nothing_to_reconcile']);

        $this->emsPost($this->staff, '/ems/api/supply/reconciliation', ['expected_last_movement_id' => $preview['last_movement_id']])->assertCreated();
        $again = $this->actingAs($this->staff)->getJson('/ems/api/supply/reconciliation')->json('data');
        $this->assertTrue($again['nothing_to_reconcile']);
        $this->assertSame(8, $this->stockOf($this->staff, 'sr.t.02')['current_quantity']);
    }

    // ------------------------------------------------------------ M1

    public function test_confirming_a_stale_preview_is_refused_and_the_expected_id_is_required(): void
    {
        $this->finalizeCount($this->staff, ['sr.t.02' => 30]);
        $this->issueTape(4);
        $preview = $this->actingAs($this->staff)->getJson('/ems/api/supply/reconciliation')->json('data');

        $this->emsPost($this->staff, '/ems/api/supply/reconciliation', [])->assertStatus(422)
            ->assertJsonPath('error.message', 'Open the reconciliation preview first, then confirm what it showed.');

        $this->issueTape(1); // someone issues while the preview is open
        $this->emsPost($this->staff, '/ems/api/supply/reconciliation', ['expected_last_movement_id' => $preview['last_movement_id']])
            ->assertStatus(409)->assertJsonPath('error.code', 'stale_preview')
            ->assertJsonPath('error.message', 'New stock changes were recorded since you opened this preview. Review the updated numbers.');
        $this->assertSame(0, DB::table('ems_inventory_snapshots')->where('kind', 'reconciliation')->count());

        $fresh = $this->actingAs($this->staff)->getJson('/ems/api/supply/reconciliation')->json('data');
        $this->assertSame(25, collect($fresh['rows'])->firstWhere('supply_item_id', $this->supplyItem('sr.t.02')->id)['calculated_quantity']);
        $this->emsPost($this->staff, '/ems/api/supply/reconciliation', ['expected_last_movement_id' => $fresh['last_movement_id']])->assertCreated();
    }

    // ------------------------------------------------------------ M2

    public function test_movement_keys_name_the_person_and_request_and_a_reused_key_for_a_different_change_is_refused(): void
    {
        $request = $this->issueTape(3);
        $movement = StockMovement::query()->sole();
        $requestId = $this->requestModel($request['uuid'])->id;
        $this->assertStringStartsWith("issue:u{$this->staff->id}:r{$requestId}:", $movement->idempotency_key);
        $this->assertLessThanOrEqual(160, strlen($movement->idempotency_key));

        $ledger = app(StockLedger::class);
        $actor = EmsActor::forUser($this->staff, 'captain5');
        $item = $this->supplyItem('sr.t.02');
        DB::transaction(function () use ($ledger, $item, $actor, $movement): void {
            $same = $ledger->record($item, StockMovement::ISSUED, -3, $actor, $movement->idempotency_key, 'again', ['request_id' => $movement->request_id, 'request_line_id' => $movement->request_line_id]);
            $this->assertSame($movement->id, $same->id);
        });
        foreach ([
            fn () => $ledger->record($item, StockMovement::ISSUED, -9, $actor, $movement->idempotency_key, 'other quantity', ['request_id' => $movement->request_id, 'request_line_id' => $movement->request_line_id]),
            fn () => $ledger->record($this->supplyItem('sr.t.01'), StockMovement::ISSUED, -3, $actor, $movement->idempotency_key, 'other item', ['request_id' => $movement->request_id, 'request_line_id' => $movement->request_line_id]),
            fn () => $ledger->record($item, StockMovement::ADJUSTED, -3, $actor, $movement->idempotency_key, 'other type', ['request_id' => $movement->request_id, 'request_line_id' => $movement->request_line_id]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A reused key was accepted for a different change.');
            } catch (EmsException $exception) {
                $this->assertSame('idempotency_mismatch', $exception->errorCode);
                $this->assertSame(409, $exception->status);
            }
        }
        $this->assertSame(1, StockMovement::query()->count());
    }

    // ------------------------------------------------------------ M3 / M4

    public function test_the_stock_lock_row_is_recreated_when_missing(): void
    {
        DB::table('ems_counters')->where('name', 'stock')->delete();

        DB::transaction(fn () => app(StockLedger::class)->lock());

        $this->assertSame(1, (int) DB::table('ems_counters')->where('name', 'stock')->value('value'));
        $this->emsPost($this->staff, '/ems/api/supply/counts', [])->assertSuccessful();
    }

    public function test_oversized_quantities_are_refused_with_a_friendly_message(): void
    {
        $count = $this->emsPost($this->staff, '/ems/api/supply/counts', [])->json('data.id');
        $tape = $this->supplyItem('sr.t.02'); // 24 per box
        $this->actingAs($this->staff)->putJson("/ems/api/supply/counts/{$count}/lines", ['lines' => [['supply_item_id' => $tape->id, 'boxes' => 999999]]], ['Idempotency-Key' => 'big-count-0001'])
            ->assertStatus(422)->assertJsonPath('error.code', 'quantity_too_large');

        $gauze = $this->supplyItem('sr.t.01'); // 200 per box
        $this->emsPost($this->staff, '/ems/api/supply/orders', ['supply_item_id' => $gauze->id, 'packages' => 100000])
            ->assertStatus(422)->assertJsonPath('error.code', 'quantity_too_large');
        $this->emsPost($this->staff, '/ems/api/supply/adjustments', ['supply_item_id' => $tape->id, 'quantity_base' => 10_000_001, 'reason' => 'too many'])->assertStatus(422);
        $this->assertSame(0, DB::table('ems_supply_orders')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    // ------------------------------------------------------------ L2

    public function test_unmatched_issuances_show_the_corrected_quantity_and_drop_out_once_matched(): void
    {
        $uuid = $this->submitRequest($this->emsMember(), [['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10]])['uuid'];
        $line = $this->actingAs($this->staff)->getJson("/ems/api/requests/{$uuid}")->json('data.lines.0');
        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $line['id'], 'quantity' => 5]]])->assertOk();
        $event = $this->requestModel($uuid)->events()->where('action', 'issued')->sole();

        $unmatched = fn (): array => $this->actingAs($this->staff)->getJson('/ems/api/supply/reconciliation')->json('data.unmatched_issuances');
        $this->assertSame([5], collect($unmatched())->pluck('quantity')->all());

        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/correct", ['event_id' => $event->id, 'new_quantity' => 3, 'reason' => 'Counted wrong'])->assertOk();
        $this->assertSame([3], collect($unmatched())->pluck('quantity')->all(), 'A correction that ends unmatched shows the corrected quantity.');

        $this->emsPost($this->staff, "/ems/api/requests/{$uuid}/correct", ['event_id' => $event->id, 'new_quantity' => 3, 'supply_item_id' => $this->supplyItem('sr.t.01')->id, 'reason' => 'Chose the item'])->assertOk();
        $this->assertSame([], $unmatched());
    }

    // ------------------------------------------------------------ L5 / L6 / L7

    public function test_history_rows_cannot_lose_their_parents(): void
    {
        $request = $this->issueTape(2);
        $requestId = $this->requestModel($request['uuid'])->id;

        foreach ([
            fn () => DB::table('ems_inventory_requests')->where('id', $requestId)->delete(),
            fn () => DB::table('ems_inventory_request_lines')->where('request_id', $requestId)->delete(),
            fn () => DB::table('ems_inventory_snapshots')->delete(),
        ] as $attempt) {
            try {
                $attempt();
                if (DB::table('ems_inventory_snapshots')->exists()) {
                    $this->fail('A parent of history rows was deleted.');
                }
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table('ems_inventory_requests')->where('id', $requestId)->count());

        $this->expectException(QueryException::class);
        DB::table('ems_stock_movements')->insert([
            'uuid' => (string) Str::uuid(), 'supply_item_id' => $this->supplyItem('sr.t.02')->id, 'movement_type' => 'adjusted', 'quantity_base' => 1,
            'occurred_at' => now(), 'corrects_movement_id' => 987654, 'idempotency_key' => 'orphan-correction', 'created_at' => now(),
        ]);
    }

    public function test_a_movement_blocks_the_deletion_of_its_request_line_and_order(): void
    {
        $request = $this->issueTape(2);
        $this->expectException(QueryException::class);
        DB::table('ems_inventory_request_lines')->where('request_id', $this->requestModel($request['uuid'])->id)->delete();
    }

    public function test_the_migrations_refuse_to_roll_back_outside_local_and_testing(): void
    {
        $files = collect(File::files(database_path('migrations')))->filter(fn ($f): bool => str_starts_with($f->getFilename(), '2026_10_24_') && str_contains($f->getFilename(), '_ems_'));
        $this->assertCount(5, $files);
        $original = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            foreach ($files as $file) {
                try {
                    (require $file->getPathname())->down();
                    $this->fail($file->getFilename().' rolled back in production.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('never', $exception->getMessage());
                }
            }
        } finally {
            $this->app->instance('env', $original);
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('ems_inventory_requests'));
    }

    public function test_who_asked_where_and_what_for_are_frozen_on_the_request_and_its_lines(): void
    {
        $request = $this->tapeRequest(2);
        $model = $this->requestModel($request['uuid']);
        $line = $model->lines->first();

        foreach ([
            fn () => $model->update(['apparatus_id' => $this->engine1->id]),
            fn () => $model->update(['submitted_by_user_id' => $this->staff->id]),
            fn () => $model->update(['submitted_by_employee_id' => $this->staff->employee_profile_id]),
            fn () => $line->update(['catalog_item_id' => null]),
            fn () => $line->update(['flags' => ['refrigerated' => true]]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A frozen column changed.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertInstanceOf(InventoryRequestLine::class, $line->fresh());
    }

    // ------------------------------------------------------------ L10 / L11 / L4

    public function test_a_fully_issued_line_cannot_be_marked_not_issued(): void
    {
        $request = $this->issueTape(2);

        $this->emsPost($this->staff, "/ems/api/requests/{$request['uuid']}/not-issued", ['line_id' => $request['line']['id'], 'reason' => 'Changed our minds'])
            ->assertStatus(422)->assertJsonPath('error.code', 'already_issued');
        $this->assertSame('issued', $this->requestModel($request['uuid'])->lines->first()->line_status);
    }

    public function test_old_idempotency_keys_are_pruned_and_the_prune_is_scheduled_daily(): void
    {
        DB::table('ems_idempotency_keys')->insert([
            ['user_id' => $this->staff->id, 'scope' => 's', 'key' => 'old-key-0001', 'response' => null, 'created_at' => now()->subDays(40)],
            ['user_id' => $this->staff->id, 'scope' => 's', 'key' => 'new-key-0001', 'response' => null, 'created_at' => now()->subDays(2)],
        ]);

        $this->artisan('ems:prune-idempotency')->assertSuccessful();

        $this->assertSame(['new-key-0001'], DB::table('ems_idempotency_keys')->where('scope', 's')->pluck('key')->all());
        $event = collect(Schedule::events())->first(fn ($e): bool => str_contains((string) $e->command, 'ems:prune-idempotency'));
        $this->assertNotNull($event);
        $this->assertSame('50 3 * * *', $event->expression);
    }

    public function test_the_same_key_on_two_requests_makes_two_distinct_messages(): void
    {
        $member = $this->emsMember();
        $one = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]])['uuid'];
        $two = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 2]])['uuid'];

        $this->emsPost($member, "/employee/forms/api/ems/requests/{$one}/messages", ['body' => 'First'], 'shared-key-0001')->assertCreated();
        $this->emsPost($member, "/employee/forms/api/ems/requests/{$two}/messages", ['body' => 'Second'], 'shared-key-0001')->assertCreated();

        $ids = DB::table('ems_inventory_request_messages')->pluck('client_message_id');
        $this->assertCount(2, $ids->unique());
    }
}
