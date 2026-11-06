<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\StockMovement;
use App\Models\User;
use App\Services\Ems\EmsStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** What Captain 5 and EMS Admin see per form: the list filter, meta, reconciliation across forms and the statistics. */
final class FormFamilyStaffTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const TAPE_ALS = ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape'];

    private const GAUZE_NON_TRANSPORT = ['form' => 'als-non-transport', 'item_key' => 'nt.gauze'];

    private const OXYGEN_BOAT = ['form' => 'fire-boat-6-als', 'item_key' => 'fb.oxygen'];

    private const TAPE_BLS = ['form' => 'bls-detail-bag', 'item_key' => 'bls.tape'];

    private User $captain;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->importFamilyLists();
        $this->captain = $this->emsCaptain('Cap Tain');
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $lines */
    private function form(array $lines, ?string $family): string
    {
        return $this->submitRequest($this->member, $lines, $family === null ? [] : ['family' => $family])['uuid'];
    }

    /** @return list<string> */
    private function listed(string $query): array
    {
        $this->actingAs($this->captain);

        return array_column($this->getJson('/ems/api/requests'.$query)->assertOk()->json('data'), 'id');
    }

    /** @return array<string, mixed> */
    private function masterRow(string $rowKey): array
    {
        $rows = collect($this->actingAs($this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data.items'));

        return $rows->firstWhere('id', $this->supplyItem($rowKey)->id);
    }

    // ------------------------------------------------------------------ list and detail

    public function test_staff_rows_and_detail_name_the_form_and_the_family_filter_counts_no_family_as_als_transport(): void
    {
        $legacy = $this->form([self::TAPE_ALS + ['quantity' => 1]], null);
        $stamped = $this->form([self::TAPE_ALS + ['quantity' => 2]], 'als-transport');
        $nonTransport = $this->form([self::GAUZE_NON_TRANSPORT + ['quantity' => 1]], 'als-non-transport');
        $boat = $this->form([self::OXYGEN_BOAT + ['quantity' => 1]], 'fire-boat-6');
        $bls = $this->form([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail');
        // The row a rolled-back image writes (or one from before the four forms): no family at all.
        DB::table('ems_inventory_requests')->where('uuid', $legacy)->update(['form_family' => null]);

        $this->assertEqualsCanonicalizing([$legacy, $stamped], $this->listed('?family=als-transport'));
        $this->assertSame([$nonTransport], $this->listed('?family=als-non-transport'));
        $this->assertSame([$boat], $this->listed('?family=fire-boat-6'));
        $this->assertSame([$bls], $this->listed('?family=bls-detail'));
        $this->assertCount(5, $this->listed(''), 'No filter lists every form.');
        $this->assertSame([$bls], $this->listed('?family=bls-detail&state=active'));

        $this->actingAs($this->captain);
        $this->getJson('/ems/api/requests?family=nope')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $rows = collect($this->getJson('/ems/api/requests')->json('data'))->keyBy('id');
        $this->assertSame(['als-transport', 'ALS Transport Inventory Form', 'ALS Transport'], [$rows[$legacy]['form_family'], $rows[$legacy]['form_family_title'], $rows[$legacy]['form_family_short']]);
        $this->assertSame(['bls-detail', 'BLS Detail Medical Inventory Form', 'BLS Detail'], [$rows[$bls]['form_family'], $rows[$bls]['form_family_title'], $rows[$bls]['form_family_short']]);

        $this->getJson("/ems/api/requests/{$boat}")->assertOk()
            ->assertJsonPath('data.form_family', 'fire-boat-6')->assertJsonPath('data.form_family_short', 'Fire Boat 6')
            ->assertJsonPath('data.form_family_title', 'Fire Boat 6 Medical Inventory Form');
        $this->getJson("/ems/api/requests/{$legacy}")->assertOk()->assertJsonPath('data.form_family', 'als-transport');
    }

    public function test_meta_lists_the_families(): void
    {
        $this->actingAs($this->captain);

        $this->getJson('/ems/api/meta')->assertOk()->assertJsonPath('data.families', [
            ['key' => 'als-transport', 'title' => 'ALS Transport Inventory Form', 'short_title' => 'ALS Transport'],
            ['key' => 'als-non-transport', 'title' => 'ALS Non Transport Inventory Form', 'short_title' => 'ALS Non Transport'],
            ['key' => 'fire-boat-6', 'title' => 'Fire Boat 6 Medical Inventory Form', 'short_title' => 'Fire Boat 6'],
            ['key' => 'bls-detail', 'title' => 'BLS Detail Medical Inventory Form', 'short_title' => 'BLS Detail'],
        ]);
    }

    public function test_the_member_list_and_detail_carry_the_family(): void
    {
        $uuid = $this->form([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail');

        $this->actingAs($this->member);
        $row = collect($this->getJson('/employee/forms/api/ems/requests')->assertOk()->json('data'))->firstWhere('id', $uuid);
        $this->assertSame(['bls-detail', 'BLS Detail Medical Inventory Form', 'BLS Detail'], [$row['form_family'], $row['form_family_title'], $row['form_family_short']]);
        $this->getJson('/employee/forms/api/ems/requests/'.$uuid)->assertOk()->assertJsonPath('data.form_family', 'bls-detail');
    }

    // ------------------------------------------------------------------- reconciliation

    public function test_a_batch_of_forms_from_different_families_deducts_exactly_once(): void
    {
        $this->finalizeCount($this->captain, ['sr.t.02' => 30]);
        $transport = $this->form([self::TAPE_ALS + ['quantity' => 5]], 'als-transport');
        $detail = $this->form([self::TAPE_BLS + ['quantity' => 3]], 'bls-detail');
        foreach ([$transport, $detail] as $uuid) {
            $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/acknowledge")->assertOk();
        }

        $preview = $this->actingAs($this->captain)->getJson("/ems/api/forms/reconcile/preview?ids={$transport},{$detail}")->assertOk()->json('data');
        $this->assertSame(0, $preview['blocked']);
        $forms = collect($preview['forms'])->keyBy('id');
        $this->assertSame('ALS Transport', $forms[$transport]['form_family_short']);
        $this->assertSame('BLS Detail', $forms[$detail]['form_family_short']);
        $this->assertSame(['ALS Transport', 'BLS Detail'], collect($preview['lines'])->pluck('form_family_short')->sort()->values()->all());
        $this->assertSame(8, $preview['totals'][0]['deduct_base'], 'Both forms take from the same shelf item.');

        $expected = (int) StockMovement::query()->max('id');
        $first = $this->emsPost($this->captain, '/ems/api/forms/reconcile', ['request_ids' => [$transport, $detail], 'expected_last_movement_id' => $expected, 'accept_low_stock' => true], 'mixed-batch-1');
        $first->assertOk();
        $this->assertSame(2, $first->json('data.movement_count'));
        $this->assertSame(22, $this->masterRow('sr.t.02')['on_hand_base']);
        $this->assertSame($this->requestModel($transport)->reconciliation_batch, $this->requestModel($detail)->reconciliation_batch);

        // The same batch again is a replay; a new attempt on either form is refused. Stock never moves twice.
        $this->emsPost($this->captain, '/ems/api/forms/reconcile', ['request_ids' => [$transport, $detail], 'expected_last_movement_id' => $expected, 'accept_low_stock' => true], 'mixed-batch-1')
            ->assertOk()->assertJsonPath('meta.replayed', true);
        foreach ([[$transport, $detail], [$detail], [$transport]] as $again) {
            $this->emsPost($this->captain, '/ems/api/forms/reconcile', ['request_ids' => $again, 'expected_last_movement_id' => (int) StockMovement::query()->max('id'), 'accept_low_stock' => true])
                ->assertStatus(409)->assertJsonPath('error.code', 'already_reconciled');
        }
        $this->assertSame(22, $this->masterRow('sr.t.02')['on_hand_base']);
        $this->assertSame(2, StockMovement::query()->count(), 'One movement per form line, and no more after the repeats.');
        $this->assertSame(InventoryRequest::RECONCILED, $this->requestModel($detail)->status);
    }

    // --------------------------------------------------------------------- statistics

    public function test_statistics_group_by_form_count_a_correction_once_and_filter_by_form(): void
    {
        $legacy = $this->form([self::TAPE_ALS + ['quantity' => 4]], null);
        DB::table('ems_inventory_requests')->where('uuid', $legacy)->update(['form_family' => null]);
        $this->form([self::GAUZE_NON_TRANSPORT + ['quantity' => 3]], 'als-non-transport');
        $this->form([self::OXYGEN_BOAT + ['quantity' => 9]], 'fire-boat-6'); // sent back and never corrected: not counted
        $returnedBoat = InventoryRequest::query()->where('form_family', 'fire-boat-6')->firstOrFail();
        $this->emsPost($this->captain, "/ems/api/requests/{$returnedBoat->uuid}/reject", ['reason' => 'Check the count'])->assertOk();
        $original = $this->form([self::TAPE_BLS + ['quantity' => 2]], 'bls-detail');
        $this->emsPost($this->captain, "/ems/api/requests/{$original}/reject", ['reason' => 'Check the count'])->assertOk();
        $this->submitRequest($this->member, [self::TAPE_BLS + ['quantity' => 5]], ['revises' => $original, 'family' => 'bls-detail']);

        $report = app(EmsStatistics::class)->report([]);
        $this->assertSame('Requests, not consumption', $report['label']);
        $this->assertSame(
            [['als-transport', 1, 4], ['als-non-transport', 1, 3], ['fire-boat-6', 0, 0], ['bls-detail', 1, 5]],
            array_map(fn (array $row): array => [$row['family'], $row['requests'], $row['qty_requested']], $report['by_family']),
            'No family counts as ALS Transport; the replaced and the sent-back forms are not counted.',
        );
        $this->assertSame('BLS Detail Medical Inventory Form', $report['by_family'][3]['title']);
        $this->assertArrayHasKey('qty_issued', $report['by_family'][0]);
        $this->assertSame(3, $report['totals']['requests_counted']);
        $this->assertSame(array_sum(array_column($report['by_family'], 'requests')), $report['totals']['requests_counted'], 'The groups add up to the total.');

        $filtered = app(EmsStatistics::class)->report(['family' => 'als-transport']);
        $this->assertSame(1, $filtered['totals']['requests_counted'], 'The form with no family is ALS Transport.');
        $this->assertSame('als-transport', $filtered['filters']['family']);
        $this->assertNull($report['filters']['family']);
        $this->assertSame(4, $filtered['totals']['qty_requested']);

        $this->actingAs($this->captain);
        $api = $this->getJson('/ems/api/statistics?family=bls-detail')->assertOk()->assertJsonPath('data.filters.family', 'bls-detail');
        $this->assertSame(1, $api->json('data.totals.requests_counted'));
        $this->assertSame(5, $api->json('data.totals.qty_requested'));
        $this->getJson('/ems/api/statistics?family=nope')->assertStatus(422);
        $this->getJson('/ems/api/statistics')->assertOk()->assertJsonCount(4, 'data.by_family');
    }

    public function test_statistics_with_no_requests_still_list_all_four_forms(): void
    {
        $this->actingAs($this->captain);

        $this->getJson('/ems/api/statistics')->assertOk()
            ->assertJsonPath('data.by_family.0.family', 'als-transport')->assertJsonPath('data.by_family.0.requests', 0)
            ->assertJsonPath('data.by_family.3.family', 'bls-detail')->assertJsonPath('data.label', 'Requests, not consumption');
        $this->assertNotSame('', (string) Str::of(EmsStatistics::DEFINITIONS['by_family']));
    }
}
