<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\CatalogSupplyLink;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\StockMovement;
use App\Models\User;
use App\Services\Ems\CountMath;
use App\Services\Ems\EmsStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** Friday forms and Monday counts: docs/ems/captain5-medical-inventory-workflow.md. */
final class MedicalInventoryWorkflowTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const TAPE = ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape'];

    private const STETHOSCOPE = ['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope'];

    private const GAUZE_TWO_CANDIDATES = ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze'];

    private const BVM_NO_ITEM = ['form' => 'airway-bag', 'item_key' => 'airway.top.bvm'];

    private const GLOVES_IN_SETS = ['form' => 'airway-bag', 'item_key' => 'airway.top.gloves'];

    private User $captain;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->captain = $this->emsCaptain('Cap Tain');
        $this->member = $this->emsMember('Firefighter', 'Fire Fighter');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $lines */
    private function form(array $lines, ?User $member = null): string
    {
        return $this->submitRequest($member ?? $this->member, $lines)['uuid'];
    }

    private function acknowledged(array $lines): string
    {
        $uuid = $this->form($lines);
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/acknowledge")->assertOk();

        return $uuid;
    }

    /** @return array<string, mixed> */
    private function preview(string ...$uuids): array
    {
        return $this->actingAs($this->captain)->getJson('/ems/api/forms/reconcile/preview?ids='.implode(',', $uuids))->assertOk()->json('data');
    }

    /** @param array<string, mixed> $extra */
    private function reconcile(array $uuids, array $extra = [], ?string $key = null): TestResponse
    {
        $expected = $extra['expected_last_movement_id'] ?? (int) StockMovement::query()->max('id');

        return $this->emsPost($this->captain, '/ems/api/forms/reconcile', ['request_ids' => $uuids, 'expected_last_movement_id' => $expected, 'accept_low_stock' => true] + $extra, $key);
    }

    private function stockCount(array $byRowKey): void
    {
        $this->finalizeCount($this->captain, $byRowKey);
    }

    /** @return array<string, mixed> */
    private function masterRow(string $rowKey): array
    {
        $rows = collect($this->actingAs($this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data.items'));

        return $rows->firstWhere('id', $this->supplyItem($rowKey)->id);
    }

    // ------------------------------------------------------- submit and acknowledge

    public function test_forms_from_engines_and_rescues_appear_active_and_acknowledging_changes_no_stock(): void
    {
        $rescue = $this->form([self::TAPE + ['quantity' => 2]]);
        $engine = $this->submitRequest($this->member, [self::TAPE + ['quantity' => 3]], ['target' => ['kind' => 'other_unit', 'apparatus_id' => $this->engine1->id]])['uuid'];

        $this->actingAs($this->captain);
        $rows = collect($this->getJson('/ems/api/requests?state=active')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$rescue, $engine], $rows->pluck('id')->all());
        $row = $rows->firstWhere('id', $engine);
        $this->assertSame('Engine 1', $row['target_label']);
        $this->assertFalse($row['acknowledged']);
        $this->assertTrue($row['can_acknowledge']);
        $this->assertFalse($row['can_reconcile']);
        $this->assertSame('Tape 1"', $row['items_preview'][0]['label']);
        $this->assertSame(3, $row['quantity_total']);

        $this->emsPost($this->captain, "/ems/api/requests/{$engine}/acknowledge")->assertOk();
        $this->emsPost($this->captain, "/ems/api/requests/{$engine}/acknowledge")->assertOk();

        $this->actingAs($this->captain);
        $row = collect($this->getJson('/ems/api/requests?state=active')->json('data'))->firstWhere('id', $engine);
        $this->assertTrue($row['acknowledged']);
        $this->assertSame('Cap Tain', $row['acknowledged_by']);
        $this->assertTrue($row['can_reconcile']);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(1, $this->requestModel($engine)->events()->where('action', 'reviewed')->count());
    }

    // -----------------------------------------------------------rejection and revision

    public function test_rejecting_returns_the_form_to_the_member_who_corrects_it_and_history_is_kept(): void
    {
        $original = $this->acknowledged([self::TAPE + ['quantity' => 2], self::STETHOSCOPE + ['quantity' => 1]]);

        $this->emsPost($this->captain, "/ems/api/requests/{$original}/reject", ['reason' => 'x'])->assertStatus(422);
        $this->emsPost($this->captain, "/ems/api/requests/{$original}/reject", ['reason' => 'Tape count looks wrong'])->assertOk();

        $this->assertSame(0, StockMovement::query()->count());
        $model = $this->requestModel($original);
        $this->assertSame(InventoryRequest::RETURNED, $model->status);
        $this->assertNull($model->reviewed_at);
        $this->assertDatabaseHas('inspection_notification_deliveries', ['recipient_user_id' => $this->member->id, 'event_key' => 'ems:request:'.$model->id.':status:'.$model->events()->where('action', 'rejected')->value('id')]);

        // Stays visible, with no further actions.
        $this->actingAs($this->captain);
        $row = collect($this->getJson('/ems/api/requests?state=active')->json('data'))->firstWhere('id', $original);
        $this->assertSame('Tape count looks wrong', $row['return_reason']);
        $this->assertFalse($row['can_acknowledge'] || $row['can_reject'] || $row['can_reconcile']);
        $this->reconcile([$original])->assertStatus(409)->assertJsonPath('error.code', 'not_eligible');

        // The member sees the reason and resubmits; a different member cannot.
        $this->actingAs($this->member);
        $mine = $this->getJson("/employee/forms/api/ems/requests/{$original}")->assertOk();
        $mine->assertJsonPath('data.can_revise', true)->assertJsonPath('data.return_reason', 'Tape count looks wrong')->assertJsonPath('data.returned_by', 'Cap Tain');
        $this->assertTrue(collect($mine->json('data.history'))->contains(fn (array $event): bool => $event['action'] === 'rejected' && $event['payload']['reason'] === 'Tape count looks wrong'));

        $other = $this->emsMember();
        $this->emsPost($other, '/employee/forms/api/ems/requests', [
            'client_submission_id' => (string) Str::uuid(), 'revises' => $original,
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id], 'lines' => [self::TAPE + ['quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'cannot_revise');

        $revision = $this->submitRequest($this->member, [self::TAPE + ['quantity' => 5]], ['revises' => $original]);
        $this->assertSame($model->number.'-R2', $revision['number']);

        $model->refresh();
        $this->assertSame(InventoryRequest::SUPERSEDED, $model->status);
        $this->assertSame(2, (int) $model->lines()->count(), 'The original lines are untouched.');
        $this->assertSame(2, $model->lines()->where('item_key', 'als.trauma.tape')->value('requested_qty'));
        $new = $this->requestModel($revision['uuid']);
        $this->assertSame(InventoryRequest::SUBMITTED, $new->status);
        $this->assertFalse($new->isAcknowledged());

        // A second correction of the same original is refused; a second round works from the revision.
        $this->emsPost($this->member, '/employee/forms/api/ems/requests', [
            'client_submission_id' => (string) Str::uuid(), 'revises' => $original,
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id], 'lines' => [self::TAPE + ['quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'cannot_revise');

        $this->actingAs($this->captain);
        $detail = $this->getJson("/ems/api/requests/{$revision['uuid']}")->assertOk()->json('data');
        $this->assertSame([$original, $revision['uuid']], array_column($detail['revisions'], 'id'));
        $this->assertSame($original, $detail['revision_of']);
        $this->assertTrue(collect($this->getJson('/ems/api/requests?state=archived')->json('data'))->pluck('id')->contains($original));
        $this->assertFalse(collect($this->getJson('/ems/api/requests?state=active')->json('data'))->pluck('id')->contains($original));

        $statistics = app(EmsStatistics::class)->report([]);
        $this->assertSame(1, $statistics['totals']['requests_counted'] ?? $statistics['requests_counted'] ?? null, 'A replaced form is not counted twice.');
    }

    // -------------------------------------------------------------- reconciliation

    public function test_one_form_reconciles_deducts_and_archives_and_cannot_deduct_twice(): void
    {
        $this->stockCount(['sr.t.02' => 30]);
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 5]]);

        $this->assertSame(0, $this->preview($uuid)['blocked']);
        $this->assertSame('ready', $this->preview($uuid)['lines'][0]['state']);
        $this->assertSame(5, $this->preview($uuid)['totals'][0]['deduct_base']);

        $first = $this->reconcile([$uuid], [], 'reconcile-key-1')->assertOk();
        $this->assertSame(1, $first->json('data.movement_count'));
        $this->assertSame(25, $this->masterRow('sr.t.02')['on_hand_base']);

        // Same key: replay. New key, second person, or a resident page: refused. Stock never moves again.
        $this->reconcile([$uuid], [], 'reconcile-key-1')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->reconcile([$uuid], [], 'reconcile-key-2')->assertStatus(409)->assertJsonPath('error.code', 'already_reconciled');
        $this->emsPost($this->emsCaptain('Second Captain'), '/ems/api/forms/reconcile', ['request_ids' => [$uuid], 'expected_last_movement_id' => (int) StockMovement::query()->max('id')])
            ->assertStatus(409)->assertJsonPath('error.code', 'already_reconciled');
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(25, $this->masterRow('sr.t.02')['on_hand_base']);

        $model = $this->requestModel($uuid);
        $this->assertSame(InventoryRequest::RECONCILED, $model->status);
        $this->assertSame('Cap Tain', $model->reconciled_by_name);
        $this->actingAs($this->captain);
        $this->assertFalse(collect($this->getJson('/ems/api/requests?state=active')->json('data'))->pluck('id')->contains($uuid));
        $this->assertTrue(collect($this->getJson('/ems/api/requests?state=archived')->json('data'))->pluck('id')->contains($uuid));
        $this->getJson("/ems/api/requests/{$uuid}")->assertOk()->assertJsonPath('data.lines.0.requested_qty', 5);

        foreach (['prepare' => ['lines' => [['line_id' => $model->lines()->first()->id, 'prepared_qty' => 1]]], 'issue' => ['issuances' => [['line_id' => $model->lines()->first()->id, 'quantity' => 1]]], 'close' => ['reason' => 'Issued in full'], 'reject' => ['reason' => 'too late']] as $action => $payload) {
            $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/{$action}", $payload)->assertStatus(409);
        }
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_several_forms_reconcile_together_or_not_at_all(): void
    {
        $this->stockCount(['sr.t.02' => 30, 'sr.t.03' => 9]);
        $one = $this->acknowledged([self::TAPE + ['quantity' => 5]]);
        $two = $this->acknowledged([self::TAPE + ['quantity' => 3], self::STETHOSCOPE + ['quantity' => 2]]);
        $notYet = $this->form([self::STETHOSCOPE + ['quantity' => 1]]);

        // One form in the selection is not eligible: nothing moves and nothing is archived.
        $this->reconcile([$one, $two, $notYet])->assertStatus(409)->assertJsonPath('error.code', 'not_eligible');
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(InventoryRequest::REVIEWED, $this->requestModel($one)->status);

        $this->reconcile([$one, $two])->assertOk();
        $this->assertSame(22, $this->masterRow('sr.t.02')['on_hand_base']);
        $this->assertSame(8, $this->masterRow('sr.t.02')['deducted_base'], 'Deductions from several forms add up into one number.');
        $this->assertSame(7, $this->masterRow('sr.t.03')['on_hand_base']);
        $this->assertSame($this->requestModel($one)->reconciliation_batch, $this->requestModel($two)->reconciliation_batch);
    }

    public function test_an_older_issuance_is_never_deducted_again(): void
    {
        $this->stockCount(['sr.t.02' => 30]);
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 5], self::STETHOSCOPE + ['quantity' => 1]]);
        $tapeLine = $this->requestModel($uuid)->lines()->where('item_key', 'als.trauma.tape')->firstOrFail();
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $tapeLine->id, 'quantity' => 5]]])->assertOk();
        $this->assertSame(25, $this->masterRow('sr.t.02')['on_hand_base']);

        $this->reconcile([$uuid])->assertStatus(409)->assertJsonPath('error.code', 'not_eligible');
        $this->assertSame(25, $this->masterRow('sr.t.02')['on_hand_base']);
        $this->actingAs($this->captain);
        $this->assertTrue(collect($this->getJson('/ems/api/requests?state=archived')->json('data'))->pluck('id')->contains($uuid), 'Partly issued older records are history, not new Friday forms.');
    }

    public function test_an_unmatched_line_blocks_until_a_person_decides_and_the_decision_is_remembered(): void
    {
        $this->stockCount(['sr.t.01' => 500, 'sr.t.07' => 100]);
        $first = $this->acknowledged([self::GAUZE_TWO_CANDIDATES + ['quantity' => 4], self::BVM_NO_ITEM + ['quantity' => 1]]);

        $preview = $this->preview($first);
        $this->assertSame(2, $preview['blocked']);
        $this->assertSame(['needs_item', 'needs_item'], array_column($preview['lines'], 'state'));

        $response = $this->reconcile([$first])->assertStatus(422)->assertJsonPath('error.code', 'needs_correction');
        $this->assertCount(2, $response->json('error.fields.lines'));
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertNotSame(InventoryRequest::RECONCILED, $this->requestModel($first)->status);

        $gauzeLine = collect($preview['lines'])->firstWhere('label', 'Gauze 4x4')['line_id'];
        $bvmLine = collect($preview['lines'])->firstWhere('label', 'BVM Adult')['line_id'];
        $this->reconcile([$first], ['decisions' => [
            ['line_id' => $gauzeLine, 'supply_item_id' => $this->supplyItem('sr.t.07')->id],
            ['line_id' => $bvmLine, 'not_stocked' => true],
        ]])->assertOk();

        $this->assertSame(96, $this->masterRow('sr.t.07')['on_hand_base']);
        $this->assertSame(500, $this->masterRow('sr.t.01')['on_hand_base']);
        $this->assertSame(CatalogSupplyLink::SOURCE_PERSON, CatalogSupplyLink::query()->where('supply_item_id', $this->supplyItem('sr.t.07')->id)->value('source'));

        // Next Friday the same items resolve themselves.
        $next = $this->acknowledged([self::GAUZE_TWO_CANDIDATES + ['quantity' => 1], self::BVM_NO_ITEM + ['quantity' => 1]]);
        $preview = $this->preview($next);
        $this->assertSame(0, $preview['blocked']);
        $this->assertEqualsCanonicalizing(['ready', 'not_stocked'], array_column($preview['lines'], 'state'));
    }

    public function test_a_unit_the_item_cannot_convert_needs_a_typed_number(): void
    {
        $this->stockCount(['sr.t.04' => 40]);
        $uuid = $this->acknowledged([self::GLOVES_IN_SETS + ['quantity' => 2]]);
        $line = $this->preview($uuid)['lines'][0];
        $this->assertSame('needs_unit', $line['state']);
        $this->assertSame('Gloves Medium', $line['item']['name']);

        $this->reconcile([$uuid])->assertStatus(422)->assertJsonPath('error.code', 'needs_correction');
        $this->reconcile([$uuid], ['decisions' => [['line_id' => $line['line_id'], 'quantity_base' => 6]]])->assertOk();
        $this->assertSame(34, $this->masterRow('sr.t.04')['on_hand_base']);
    }

    public function test_captain_5_can_lower_a_quantity_and_zero_means_not_taken(): void
    {
        $this->stockCount(['sr.t.02' => 30, 'sr.t.03' => 9]);
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 5], self::STETHOSCOPE + ['quantity' => 2]]);
        $lines = collect($this->preview($uuid)['lines']);

        $this->reconcile([$uuid], ['decisions' => [
            ['line_id' => $lines->firstWhere('label', 'Tape 1"')['line_id'], 'quantity' => 3],
            ['line_id' => $lines->firstWhere('label', 'Stethoscope')['line_id'], 'quantity' => 0],
        ]])->assertOk();

        $this->assertSame(27, $this->masterRow('sr.t.02')['on_hand_base']);
        $this->assertSame(9, $this->masterRow('sr.t.03')['on_hand_base']);
        $this->assertSame(0, $this->masterRow('sr.t.03')['deducted_base']);
        $this->assertSame('not_issued', $this->requestModel($uuid)->lines()->where('item_key', 'airway.top.stethoscope')->value('line_status'));
    }

    public function test_more_than_the_books_show_needs_confirmation_and_a_stale_preview_is_refused(): void
    {
        $this->stockCount(['sr.t.02' => 3]);
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 5]]);
        $preview = $this->preview($uuid);
        $this->assertTrue($preview['totals'][0]['low']);
        $this->assertSame(-2, $preview['totals'][0]['after_base']);

        $this->emsPost($this->captain, '/ems/api/forms/reconcile', ['request_ids' => [$uuid], 'expected_last_movement_id' => $preview['last_movement_id']])
            ->assertStatus(409)->assertJsonPath('error.code', 'low_stock');
        $this->assertSame(0, StockMovement::query()->count());

        $this->emsPost($this->captain, '/ems/api/supply/adjustments', ['supply_item_id' => $this->supplyItem('sr.t.02')->id, 'quantity_base' => 1, 'reason' => 'found one'])->assertSuccessful();
        $this->reconcile([$uuid], ['expected_last_movement_id' => $preview['last_movement_id']])->assertStatus(409)->assertJsonPath('error.code', 'stale_preview');

        $this->reconcile([$uuid])->assertOk();
        $this->assertSame(-1, $this->masterRow('sr.t.02')['on_hand_base']);
    }

    public function test_controlled_and_yes_no_lines_never_touch_stock(): void
    {
        $uuid = $this->acknowledged([['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => true], self::TAPE + ['quantity' => 1]]);
        $states = array_column($this->preview($uuid)['lines'], 'state', 'label');
        $this->assertSame('no_stock_effect', $states['Narcs Box']);
        $this->assertSame('ready', $states['Tape 1"']);
        $this->reconcile([$uuid])->assertOk();
        $this->assertSame(1, StockMovement::query()->count(), 'Only the tape moved. The narcotics box stays with its own procedure.');
    }

    public function test_a_correction_on_a_reconciled_form_goes_through_the_ledger_and_the_red_number_follows(): void
    {
        $this->stockCount(['sr.t.02' => 30]);
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 5]]);
        $this->reconcile([$uuid])->assertOk();
        $event = $this->requestModel($uuid)->events()->where('action', 'issued')->firstOrFail();

        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/correct", ['event_id' => $event->id, 'new_quantity' => 2, 'reason' => 'Only took two'])->assertOk();

        $row = $this->masterRow('sr.t.02');
        $this->assertSame(28, $row['on_hand_base']);
        $this->assertSame(2, $row['deducted_base']);
        $this->assertSame(InventoryRequest::RECONCILED, $this->requestModel($uuid)->status);
    }

    // ------------------------------------------------ returning a day's forms to Captain 5

    public function test_a_form_issued_under_the_older_workflow_returns_and_ends_with_one_deduction(): void
    {
        $issued = $this->acknowledged([self::TAPE + ['quantity' => 5], self::GLOVES_IN_SETS + ['quantity' => 2]]);
        $untouched = $this->form([self::STETHOSCOPE + ['quantity' => 1]]);
        $model = $this->requestModel($issued);
        $tapeLine = $model->lines()->where('item_key', 'als.trauma.tape')->firstOrFail();
        $glovesLine = $model->lines()->where('item_key', 'airway.top.gloves')->firstOrFail();
        $this->emsPost($this->captain, "/ems/api/requests/{$issued}/issue", ['issuances' => [['line_id' => $tapeLine->id, 'quantity' => 5], ['line_id' => $glovesLine->id, 'quantity' => 2]]])->assertOk();
        $this->assertSame(InventoryRequest::ISSUED, $model->fresh()->status);
        $date = $model->submitted_at->setTimezone('America/New_York')->format('Y-m-d');

        // A dry run writes nothing.
        $this->artisan('ems:return-forms-to-captain5', ['--date' => $date])->assertSuccessful();
        $this->assertSame(InventoryRequest::ISSUED, $model->fresh()->status);

        $this->artisan('ems:return-forms-to-captain5', ['--date' => $date, '--apply' => true])->assertSuccessful();
        $model->refresh();
        $this->assertSame(InventoryRequest::REVIEWED, $model->status);
        $this->assertSame('Cap Tain', $model->reviewed_by_name);
        $this->assertSame(0, (int) $model->lines()->sum('issued_qty'));
        $this->assertSame(0, (int) StockMovement::query()->where('supply_item_id', $this->supplyItem('sr.t.02')->id)->sum('quantity_base'), 'The older deduction is reversed, and both rows stay in history.');
        $this->assertSame(2, StockMovement::query()->where('supply_item_id', $this->supplyItem('sr.t.02')->id)->count());
        $this->assertSame(1, $model->events()->where('action', 'returned_to_forms')->count());
        $this->assertSame(InventoryRequest::SUBMITTED, $this->requestModel($untouched)->status, 'A form nobody worked is left alone.');

        // Running it again changes nothing.
        $events = $model->events()->count();
        $this->artisan('ems:return-forms-to-captain5', ['--date' => $date, '--apply' => true])->assertSuccessful();
        $this->assertSame($events, $model->events()->count());

        // The starting count goes on after it, then Captain 5 reconciles: one deduction stands.
        $this->stockCount(['sr.t.02' => 30, 'sr.t.04' => 40]);
        $this->actingAs($this->captain);
        $row = collect($this->getJson('/ems/api/requests?state=active')->json('data'))->firstWhere('id', $issued);
        $this->assertTrue($row['can_reconcile'], 'It can be selected right away.');

        $line = collect($this->preview($issued)['lines'])->firstWhere('state', 'needs_unit');
        $this->reconcile([$issued], ['decisions' => [['line_id' => $line['line_id'], 'quantity_base' => 2]]])->assertOk();
        $tape = $this->masterRow('sr.t.02');
        $this->assertSame(25, $tape['on_hand_base']);
        $this->assertSame(5, $tape['deducted_base']);
        $this->assertSame(InventoryRequest::RECONCILED, $model->fresh()->status);
    }

    public function test_a_form_cannot_return_once_a_count_covers_what_it_took(): void
    {
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 5]]);
        $model = $this->requestModel($uuid);
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $model->lines()->firstOrFail()->id, 'quantity' => 5]]])->assertOk();
        $this->stockCount(['sr.t.02' => 25]);

        $this->artisan('ems:return-forms-to-captain5', ['--date' => $model->submitted_at->setTimezone('America/New_York')->format('Y-m-d'), '--apply' => true])->assertFailed();

        $this->assertSame(InventoryRequest::ISSUED, $model->fresh()->status);
        $this->assertSame(25, $this->masterRow('sr.t.02')['on_hand_base'], 'The count stands; no stock appears from nowhere.');
    }

    public function test_a_note_only_form_reconciles_with_no_stock_change(): void
    {
        $uuid = $this->submitRequest($this->member, [], ['member_note' => 'Need the drug box checked'])['uuid'];
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/acknowledge")->assertOk();
        $this->assertSame([], $this->preview($uuid)['lines']);
        $this->reconcile([$uuid])->assertOk()->assertJsonPath('data.movement_count', 0);
        $this->assertSame(InventoryRequest::RECONCILED, $this->requestModel($uuid)->status);
    }

    public function test_a_bad_selection_or_a_bad_chosen_item_is_refused_before_anything_moves(): void
    {
        $this->actingAs($this->captain)->getJson('/ems/api/forms/reconcile/preview?ids=not-a-uuid')->assertStatus(422);

        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 1]]);
        $line = $this->preview($uuid)['lines'][0]['line_id'];
        foreach ([999999, $this->supplyItem('sr.t.06')->id] as $bad) { // nonexistent, then controlled
            $this->reconcile([$uuid], ['decisions' => [['line_id' => $line, 'quantity' => 0, 'supply_item_id' => $bad]]])
                ->assertStatus(422)->assertJsonPath('error.code', 'unknown_supply_item');
        }
        $this->assertSame(0, CatalogSupplyLink::query()->count());
        $this->assertNotSame(InventoryRequest::RECONCILED, $this->requestModel($uuid)->status);
    }

    public function test_a_direct_count_made_during_an_open_count_is_not_overridden_at_submit(): void
    {
        $this->stockCount(['sr.t.02' => 100]);
        $tape = $this->supplyItem('sr.t.02');
        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($this->captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [
            ['supply_item_id' => $tape->id, 'on_hand' => 8], ['supply_item_id' => $this->supplyItem('sr.t.03')->id, 'on_hand' => 4],
        ]], ['Idempotency-Key' => 'save-'.Str::random(12)])->assertOk();

        $this->emsPost($this->captain, "/ems/api/supply/master/items/{$tape->id}/count", ['on_hand' => 12])->assertOk();

        $review = $this->getJson("/ems/api/supply/count-form/{$count}/review")->assertOk()->json('data');
        $this->assertContains('Tape 1 inch', array_column($review['uncounted'], 'name'), 'The older typed number is gone, so the review shows it as missing.');
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => count($review['uncounted'])])->assertOk();
        $this->assertSame(12, $this->masterRow('sr.t.02')['on_hand_base']);
    }

    public function test_packaging_cannot_be_pulled_out_from_under_an_item_counted_in_packages(): void
    {
        $gauze = $this->supplyItem('sr.t.01');
        $gauze->update(['count_unit' => 'box']);
        $headers = ['Idempotency-Key' => 'edit-'.Str::random(12)];

        // The older settings endpoint refuses, so the rule holds for every writer.
        $admin = $this->emsAdmin(['admin.ems.manage']);
        $this->actingAs($admin)->patchJson("/ems/api/supply/items/{$gauze->id}", ['units_per_package' => null, 'reason' => 'clean up'], $headers)
            ->assertStatus(422)->assertJsonPath('error.code', 'counted_in_package');

        // And a row that got there anyway refuses a typed number instead of storing the wrong quantity.
        $gauze->update(['units_per_package' => null]);
        $this->emsPost($this->captain, "/ems/api/supply/master/items/{$gauze->id}/count", ['on_hand' => 3])->assertStatus(422)->assertJsonPath('error.code', 'package_size_needed');
        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($this->captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $gauze->id, 'on_hand' => 3]]], ['Idempotency-Key' => 'save-'.Str::random(12)])
            ->assertStatus(422)->assertJsonPath('error.code', 'package_size_needed');
    }

    public function test_how_an_item_is_counted_cannot_change_while_it_has_a_number_in_the_open_count(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($this->captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $tape->id, 'on_hand' => 8]]], ['Idempotency-Key' => 'save-'.Str::random(12)])->assertOk();

        $this->patchJson("/ems/api/supply/master/items/{$tape->id}", ['count_unit' => 'box', 'units_per_package' => 24], ['Idempotency-Key' => 'edit-'.Str::random(12)])
            ->assertStatus(409)->assertJsonPath('error.code', 'item_in_open_count');

        // A rename made while the count is open is what the report shows.
        $this->patchJson("/ems/api/supply/master/items/{$tape->id}", ['name' => 'Silk Tape 1 inch'], ['Idempotency-Key' => 'edit-'.Str::random(12)])->assertOk();
        $missing = count($this->getJson("/ems/api/supply/count-form/{$count}/review")->json('data.uncounted'));
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => $missing])->assertOk();
        $this->assertSame('Silk Tape 1 inch', InventorySnapshot::query()->where('uuid', $count)->firstOrFail()->lines()->where('supply_item_id', $tape->id)->value('item_name'));
    }

    // ---------------------------------------------------------- master inventory

    public function test_live_quantity_and_red_number_match_the_friday_monday_example(): void
    {
        $this->stockCount(['sr.t.02' => 100, 'sr.t.03' => 9]);
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 15], self::STETHOSCOPE + ['quantity' => 1]]);
        $this->reconcile([$uuid])->assertOk();

        $row = $this->masterRow('sr.t.02');
        $this->assertSame(85, $row['on_hand_base']);
        $this->assertSame(15, $row['deducted_base']);

        // A refresh or a calculated snapshot is not a count, so the red number stays.
        $last = (int) $this->actingAs($this->captain)->getJson('/ems/api/supply/reconciliation')->assertOk()->json('data.last_movement_id');
        $this->emsPost($this->captain, '/ems/api/supply/reconciliation', ['expected_last_movement_id' => $last])->assertSuccessful();
        $row = $this->masterRow('sr.t.02');
        $this->assertSame(85, $row['on_hand_base']);
        $this->assertSame(15, $row['deducted_base']);

        // A direct physical correction clears only that item.
        $this->emsPost($this->captain, '/ems/api/supply/master/items/'.$this->supplyItem('sr.t.03')->id.'/count', ['on_hand' => 8])->assertOk();
        $this->assertSame(0, $this->masterRow('sr.t.03')['deducted_base']);
        $this->assertSame(8, $this->masterRow('sr.t.03')['on_hand_base']);
        $this->assertSame(15, $this->masterRow('sr.t.02')['deducted_base']);

        // Monday: the physical count wins, the red number goes, and the movements stay in history.
        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($this->captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $this->supplyItem('sr.t.02')->id, 'on_hand' => 82]]], ['Idempotency-Key' => 'save-'.Str::random(12)])->assertOk();
        $missing = count($this->getJson("/ems/api/supply/count-form/{$count}/review")->assertOk()->json('data.uncounted'));
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => $missing])->assertOk();

        $row = $this->masterRow('sr.t.02');
        $this->assertSame(82, $row['on_hand_base']);
        $this->assertSame(0, $row['deducted_base']);
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame(8, $this->masterRow('sr.t.03')['on_hand_base'], 'An item the count did not cover keeps its number.');
    }

    public function test_the_master_lists_shelves_and_only_tracked_items(): void
    {
        $data = $this->actingAs($this->captain)->getJson('/ems/api/supply/master')->assertOk()->json('data');
        $this->assertSame(['A2', 'B1', 'C1', 'D1', 'A3'], array_column($data['sections'], 'code'), 'Shelves follow their stored order, not the alphabet.');
        $this->assertNull(collect($data['items'])->firstWhere('name', 'Fentanyl (Narcotics Locker)'), 'Controlled items stay out.');
        $this->assertNull($data['items'][0]['on_hand'], 'Not counted stays blank, never zero.');
        $this->assertTrue($data['can_edit']);
    }

    public function test_rename_and_move_keep_the_item_identity_and_are_audited(): void
    {
        $item = $this->supplyItem('sr.t.03');
        $this->stockCount(['sr.t.03' => 9]);
        $response = $this->actingAs($this->captain)->patchJson("/ems/api/supply/master/items/{$item->id}", [
            'name' => 'Littmann Stethoscope', 'new_location_label' => 'Cabinet Z', 'threshold' => 4,
        ], ['Idempotency-Key' => 'edit-'.Str::random(12)])->assertOk();

        $response->assertJsonPath('data.name', 'Littmann Stethoscope')->assertJsonPath('data.location_code', 'CABINET Z')->assertJsonPath('data.required_base', 4);
        $this->assertSame($item->catalog_item_id, $item->fresh()->catalog_item_id);
        $this->assertSame(9, $response->json('data.on_hand_base'));
        $this->assertSame(1, $item->events()->where('field', 'name')->count());
        $this->assertSame('Cap Tain', $item->events()->where('field', 'name')->value('actor_name'));
        // The stock tracking and the form link still resolve to the same row.
        $uuid = $this->acknowledged([self::STETHOSCOPE + ['quantity' => 1]]);
        $this->assertSame($item->id, $this->preview($uuid)['lines'][0]['item']['id']);
    }

    public function test_a_new_item_can_be_added_and_a_box_item_needs_its_size(): void
    {
        $this->actingAs($this->captain)->postJson('/ems/api/supply/master/items', ['name' => 'New Thing', 'location_code' => 'A2', 'count_unit' => 'box'], ['Idempotency-Key' => 'add-'.Str::random(12)])
            ->assertStatus(422)->assertJsonPath('error.code', 'package_size_needed');
        $created = $this->postJson('/ems/api/supply/master/items', ['name' => 'New Thing', 'location_code' => 'A2', 'count_unit' => 'box', 'units_per_package' => 10, 'threshold' => 2, 'on_hand' => 3], ['Idempotency-Key' => 'add-'.Str::random(12)])->assertCreated();
        $created->assertJsonPath('data.on_hand', 3)->assertJsonPath('data.on_hand_base', 30)->assertJsonPath('data.required_base', 20)->assertJsonPath('data.count_unit_label_plural', 'boxes');
        $this->postJson('/ems/api/supply/master/items', ['name' => 'new thing', 'location_code' => 'A2'], ['Idempotency-Key' => 'add-'.Str::random(12)])->assertStatus(409);
    }

    public function test_every_write_is_refused_without_act_access(): void
    {
        $item = $this->supplyItem('sr.t.03');
        $viewer = $this->emsAdmin(['admin.ems.view']);
        foreach ([$this->member, $viewer] as $user) {
            $this->actingAs($user);
            $headers = ['Idempotency-Key' => 'denied-'.Str::random(12)];
            $this->postJson("/ems/api/supply/master/items/{$item->id}/count", ['on_hand' => 1], $headers)->assertForbidden();
            $this->patchJson("/ems/api/supply/master/items/{$item->id}", ['name' => 'X'], $headers)->assertForbidden();
            $this->postJson('/ems/api/supply/count-form/start', [], $headers)->assertForbidden();
            $this->postJson('/ems/api/forms/reconcile', ['request_ids' => [], 'expected_last_movement_id' => 0], $headers)->assertForbidden();
        }
        $this->actingAs($this->member)->getJson('/ems/api/supply/master')->assertForbidden();
        $this->actingAs($viewer)->getJson('/ems/api/supply/master')->assertOk()->assertJsonPath('data.can_edit', false);
    }

    // ---------------------------------------------------------------- Monday count

    public function test_order_math_matches_the_written_examples(): void
    {
        foreach ([[10, 5, 5], [10, 8, 2], [10, 10, 0], [10, 15, 0], [25, 0, 25]] as [$required, $counted, $expected]) {
            $this->assertSame($expected, CountMath::orderBase($required, $counted));
        }
        $this->assertSame(1, CountMath::orderInCountUnits(CountMath::orderBase(500, 250), 250));
        $this->assertSame(2, CountMath::orderInCountUnits(CountMath::orderBase(500, 0), 250));
        $this->assertSame(1, CountMath::orderInCountUnits(CountMath::orderBase(25, 0), 50));
        $this->assertNull(CountMath::orderBase(null, 3));
    }

    public function test_zero_and_blank_differ_and_a_report_is_frozen_when_submitted(): void
    {
        $tape = $this->supplyItem('sr.t.02');
        $stethoscope = $this->supplyItem('sr.t.03');
        $gauze = $this->supplyItem('sr.t.01');
        $gauze->update(['count_unit' => 'box']);

        $this->actingAs($this->captain);
        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $save = fn (array $entries) => $this->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => $entries], ['Idempotency-Key' => 'save-'.Str::random(12)]);

        $save([['supply_item_id' => $tape->id, 'on_hand' => 0], ['supply_item_id' => $stethoscope->id, 'on_hand' => 2], ['supply_item_id' => $gauze->id, 'on_hand' => 0]])->assertOk();
        $save([['supply_item_id' => $stethoscope->id, 'on_hand' => null]])->assertOk()->assertJsonMissingPath('data.entries.'.$stethoscope->id);
        $save([['supply_item_id' => $stethoscope->id, 'on_hand' => 1]])->assertOk();

        // Interrupted: starting again returns the same count with what was typed.
        $resumed = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk();
        $resumed->assertJsonPath('data.resumed', true)->assertJsonPath('data.count.id', $count)->assertJsonPath('data.count.entries.'.$tape->id, 0);

        $review = $this->getJson("/ems/api/supply/count-form/{$count}/review")->assertOk()->json('data');
        $this->assertSame(3, $review['counted']);
        $this->assertSame(['Gloves Medium', 'Cardizem 25mg', 'Gauze 4x4 Sterile'], array_column($review['uncounted'], 'name'));
        $orders = collect($review['orders'])->keyBy('name');
        $this->assertSame(12, $orders['Tape 1 inch']['order'], 'A typed zero is a real count of zero.');
        $this->assertSame(2, $orders['Stethoscope']['order']);
        $this->assertSame(1, $orders['Gauze 4x4']['order'], 'Fifty units on order level rounds up to one box of two hundred.');
        $this->assertSame('box', $orders['Gauze 4x4']['unit_label']);

        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => 0])->assertStatus(409)->assertJsonPath('error.code', 'uncounted_changed');
        $key = 'submit-'.Str::random(12);
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => 3], $key)->assertOk()->assertJsonPath('data.uncounted_items', 3)->assertJsonPath('data.order_items', 3);
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => 3], $key)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => 3])->assertStatus(409)->assertJsonPath('error.code', 'count_final');

        $snapshot = InventorySnapshot::query()->where('uuid', $count)->firstOrFail();
        $this->assertSame(InventorySnapshot::ORIGIN_COUNT_FORM, $snapshot->origin);
        $this->assertSame(1, InventorySnapshot::query()->where('status', 'final')->count());
        $this->assertSame(0, $this->masterRow('sr.t.02')['on_hand_base']);
        $this->assertNull($this->masterRow('sr.t.04')['on_hand_base'], 'Not counted stays not counted.');

        // Later edits never reach a submitted report.
        $this->actingAs($this->captain)->patchJson("/ems/api/supply/master/items/{$tape->id}", ['name' => 'Renamed', 'location_code' => 'B1', 'threshold' => 99], ['Idempotency-Key' => 'edit-'.Str::random(12)])->assertOk();
        $line = $snapshot->lines()->where('supply_item_id', $tape->id)->firstOrFail();
        $this->assertSame('Tape 1 inch', $line->item_name);
        $this->assertSame('A2', $line->location_code);
        $this->assertSame(12, $line->required_qty_base);
        $this->assertSame(12, $line->order_qty_count_unit);
        $notCounted = $snapshot->lines()->where('supply_item_id', $this->supplyItem('sr.t.04')->id)->firstOrFail();
        $this->assertSame('not_counted', $notCounted->value_source);
        $this->assertNull($notCounted->quantity_base);
    }

    public function test_a_movement_while_a_count_is_open_is_not_lost(): void
    {
        $this->stockCount(['sr.t.02' => 100]);
        $uuid = $this->acknowledged([self::TAPE + ['quantity' => 5]]);
        $count = $this->emsPost($this->captain, '/ems/api/supply/count-form/start')->assertOk()->json('data.count.id');
        $this->actingAs($this->captain)->putJson("/ems/api/supply/count-form/{$count}/entries", ['entries' => [['supply_item_id' => $this->supplyItem('sr.t.02')->id, 'on_hand' => 90]]], ['Idempotency-Key' => 'save-'.Str::random(12)])->assertOk();

        $this->reconcile([$uuid])->assertOk();

        $missing = count($this->actingAs($this->captain)->getJson("/ems/api/supply/count-form/{$count}/review")->json('data.uncounted'));
        $this->emsPost($this->captain, "/ems/api/supply/count-form/{$count}/submit", ['expected_uncounted' => $missing])->assertOk();

        $row = $this->masterRow('sr.t.02');
        $this->assertSame(85, $row['on_hand_base'], 'Typed 90, then 5 left the shelf.');
        $this->assertSame(5, $row['deducted_base']);
    }
}
