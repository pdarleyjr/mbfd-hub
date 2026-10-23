<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\ApparatusChecklistRevision;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryChangeSet;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind as Kind;
use App\Services\Inventory\InventoryChangePlanner;
use App\Services\Inventory\InventoryCorrectionConflict;
use App\Services\Inventory\InventoryCorrectionProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/**
 * docs/apparatus-inventory-corrections.md acceptance scenarios A-J, asserting persisted
 * state and the checklist the next inspection is issued.
 */
final class InventoryCorrectionScenariosTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
    }

    public function test_deploying_the_overlay_changes_no_checklist_version(): void
    {
        foreach (array_keys($this->units) as $designation) {
            $resolution = $this->resolved($designation);
            $this->assertSame($resolution['checklist_base_version'], $resolution['checklist_version'], "{$designation} keeps its template hash");
            $this->assertSame([], $resolution['inventory_change_ids']);
        }
    }

    /** A: one unit's item moves; the next checklist shows it, the other units are untouched, defect history continues. */
    public function test_a_single_unit_relocation_reaches_the_next_checklist_only_for_that_unit(): void
    {
        $binoculars = $this->item('E1', 'Binoculars');
        $this->assertSame('front_cab', $binoculars['compartment_id']);
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $this->inspectionPayload('E1', 'Binoculars'))->assertCreated();
        $defect = ApparatusDefect::query()->where('apparatus_id', $this->units['E1']->id)->where('item', 'Binoculars')->sole();
        $e3Before = $this->resolved('E3')['checklist_version'];
        $e1Before = $this->resolved('E1')['checklist_version'];
        $logistics = $this->logistics();

        $correction = $this->reported('E1', [
            'reason' => 'moved', 'compartment_id' => 'front_cab', 'item_id' => $binoculars['item_id'],
            'proposal' => ['to_compartment_id' => 'rear_cab'], 'note' => 'Moved behind the officer seat.',
        ]);
        $this->assertSame('front_cab', $this->item('E1', 'Binoculars')['compartment_id'], 'nothing changes before approval');
        Notification::assertSentTo($logistics, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === Kind::INVENTORY_CORRECTION_SUBMITTED);

        $this->approve($correction, $logistics, ['op' => 'modify', 'fields' => ['compartment_id' => 'rear_cab']]);

        $moved = $this->item('E1', 'Binoculars');
        $this->assertSame('rear_cab', $moved['compartment_id']);
        $this->assertSame($binoculars['item_id'], $moved['item_id'], 'the stable item id is kept');
        $this->assertContains('Front Cab', $moved['item']['legacyCompartmentNames']);
        $this->assertNotSame($e1Before, $this->resolved('E1')['checklist_version']);
        $this->assertSame($e3Before, $this->resolved('E3')['checklist_version'], 'E3 is untouched');
        $this->assertSame('front_cab', $this->item('E3', 'Binoculars')['compartment_id']);
        $this->assertSame('applied', $correction->fresh()->status);

        // The same physical item reported missing again is the same issue, not a new one.
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $this->inspectionPayload('E1', 'Binoculars'))->assertCreated();
        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $this->units['E1']->id)->where('item', 'Binoculars')->count());
        $this->assertSame(2, $defect->observations()->count());
    }

    /** B: model replacement edited by Logistics; the member hears about it; history and evidence stay intact. */
    public function test_b_model_replacement_is_modified_and_approved_and_history_is_intact(): void
    {
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $this->inspectionPayload('E1'))->assertCreated();
        $prior = ApparatusInspection::query()->latest('id')->firstOrFail();
        $priorResults = $prior->results;
        $toughbook = $this->item('E1', 'Toughbook');

        $correction = $this->reported('E1', [
            'reason' => 'replaced', 'compartment_id' => $toughbook['compartment_id'], 'item_id' => $toughbook['item_id'],
            'proposal' => ['manufacturer' => 'Panasonic', 'model' => 'CF-33'], 'photo' => self::PNG,
        ]);
        $this->assertNotNull($correction->photo_path);

        $logistics = $this->logistics();
        $this->approve($correction, $logistics, ['op' => 'modify', 'fields' => ['manufacturer' => 'Panasonic', 'model' => 'CF-33 mk2']]);

        $item = $this->item('E1', 'Toughbook')['item'];
        $this->assertSame('Panasonic', $item['manufacturer']);
        $this->assertSame('CF-33 mk2', $item['model']);
        $this->assertSame('name:toughbook', $item['equipmentKey'], 'identity is pinned to the template name');
        $fresh = $correction->fresh();
        $this->assertSame(['manufacturer' => 'Panasonic', 'model' => 'CF-33'], $fresh->proposal, 'the original proposal is kept');
        $this->assertTrue($fresh->decision['modified']);
        $this->assertSame('CF-33 mk2', $fresh->decision['operation']['fields']['model']);
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === Kind::INVENTORY_CORRECTION_UPDATE && str_contains($n->title, 'approved'));
        $prior->refresh();
        $this->assertSame($priorResults, $prior->results, 'submitted evidence never changes');
        $this->assertNotSame($prior->checklist_version, $this->resolved('E1')['checklist_version']);
        $detail = app(InventoryCorrectionProjection::class)->memberDetail($fresh);
        $this->assertSame(['E1'], $detail['applied_to']);
    }

    /** C: a removed item is still on the checklist until approval and gone after; earlier inspections keep it. */
    public function test_c_removal_takes_effect_only_after_approval(): void
    {
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $this->inspectionPayload('E1'))->assertCreated();
        $prior = ApparatusInspection::query()->latest('id')->firstOrFail();
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']]);

        $this->assertTrue($this->hasItem('E1', $passports['item_id']));
        $this->getJson("/api/public/apparatuses/{$this->units['E1']->id}/checklist")->assertOk()
            ->assertJsonPath('pending_corrections.0.item_id', $passports['item_id'])
            ->assertJsonPath('pending_corrections.0.mine', true);

        $this->approve($correction, $this->logistics(), ['op' => 'remove']);

        $this->assertFalse($this->hasItem('E1', $passports['item_id']));
        $this->assertTrue($this->hasItem('E3', $passports['item_id']));
        $this->getJson("/api/public/apparatuses/{$this->units['E1']->id}/checklist")->assertOk()->assertJsonCount(0, 'pending_corrections');
        $names = collect($prior->fresh()->results)->flatMap(fn (array $compartment) => array_column($compartment['items'], 'name'));
        $this->assertContains('Passports', $names->all());
    }

    /** D: every engine, including the Engine 2 variant, by item identity and destination name; reserves opt-in. */
    public function test_d_family_scope_handles_engine_variants_and_reserves(): void
    {
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['to_compartment_id' => 'comp_a1']]);
        $logistics = $this->logistics();
        $operation = ['op' => 'modify', 'fields' => ['compartment_id' => 'comp_a1', 'expectedQuantity' => 3]];

        ['plan' => $plan] = $this->approve($correction, $logistics, $operation, InventoryChangePlanner::SCOPE_FAMILY);

        $this->assertSame('eligible', $this->verdictFor($plan, 'E3'));
        $this->assertSame('eligible', $this->verdictFor($plan, 'E2'));
        $this->assertSame('not_daily_checkout', $this->verdictFor($plan, 'E21'));
        $this->assertNull($this->verdictFor($plan, 'L1'), 'ladders are not engines');
        $e2Unit = collect($plan['units'])->firstWhere('apparatus_id', $this->units['E2']->id);
        $this->assertSame('comp_l1', $e2Unit['destination_compartment_id'], 'Driver L4 is comp_l1 on Engine 2');

        foreach (['E1' => 'comp_a1', 'E3' => 'comp_a1', 'E2' => 'comp_l1'] as $designation => $compartment) {
            $moved = $this->item($designation, 'Passports');
            $this->assertSame($compartment, $moved['compartment_id'], $designation);
            $this->assertSame(3, $moved['item']['expectedQuantity'], $designation);
        }
        $this->assertSame('front_cab', $this->item('E21', 'Passports')['compartment_id'], 'the reserve stays as it was');
        $this->assertSame(3, ApparatusInventoryChange::query()->where('apparatus_inventory_correction_id', $correction->id)->count());
    }

    /** E: every apparatus carrying the item, across families, with different template ids. */
    public function test_e_equipment_scope_reaches_every_family_by_identity(): void
    {
        $passports = $this->item('E1', 'Passports');
        $ladderPassports = $this->item('L1', 'Passports');
        $this->assertNotSame($passports['item_id'], $ladderPassports['item_id'], 'templates use different ids');
        $correction = $this->reported('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 4]]);

        ['plan' => $plan] = $this->approve($correction, $this->logistics(), ['op' => 'modify', 'fields' => ['expectedQuantity' => 4]], InventoryChangePlanner::SCOPE_EQUIPMENT);

        foreach (['E1', 'E3', 'E2', 'L1', 'L3', 'R1'] as $designation) {
            $this->assertSame('eligible', $this->verdictFor($plan, $designation), $designation);
            $this->assertSame(4, $this->item($designation, 'Passports')['item']['expectedQuantity'], $designation);
        }
        $this->assertSame(2, $this->item('E21', 'Passports')['item']['expectedQuantity'], 'the reserve was not selected');
    }

    /** F: rejection tells the member why and changes nothing. */
    public function test_f_rejection_changes_nothing_and_tells_the_member(): void
    {
        $passports = $this->item('E1', 'Passports');
        $before = $this->resolved('E1')['checklist_version'];
        $correction = $this->reported('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']]);

        $this->service()->reject($correction->id, $this->logistics(), 'Passports stay on every engine per SOP.', 'Checked with the chief.', $correction->lock_version);

        $this->assertSame('rejected', $correction->fresh()->status);
        $this->assertSame($before, $this->resolved('E1')['checklist_version']);
        $this->assertSame(0, ApparatusInventoryChange::query()->count());
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->body, 'per SOP'));
        $detail = app(InventoryCorrectionProjection::class)->memberDetail($correction->fresh());
        $this->assertStringNotContainsString('chief', json_encode($detail, JSON_THROW_ON_ERROR), 'internal notes never reach the member');
    }

    /** G: clarification round trip, then approval at the new lock version. */
    public function test_g_clarification_round_trip(): void
    {
        $toughbook = $this->item('E1', 'Toughbook');
        $correction = $this->reported('E1', ['reason' => 'other', 'compartment_id' => $toughbook['compartment_id'], 'item_id' => $toughbook['item_id'], 'note' => 'Something is off with this one.']);
        $logistics = $this->logistics();

        $this->service()->requestClarification($correction->id, $logistics, 'What exactly is wrong?', $correction->lock_version);
        $this->assertSame('clarification_requested', $correction->fresh()->status);
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->title, 'question'));

        $this->service()->postMessage($correction->id, $this->member, 'It is a CF-33 now.', 'member', (string) Str::uuid());
        $this->assertSame('submitted', $correction->fresh()->status, 'the answer returns it to the queue');
        Notification::assertSentTo($logistics, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === Kind::INVENTORY_CORRECTION_REPLY);

        try {
            $this->service()->reject($correction->id, $logistics, 'stale', null, 0);
            $this->fail('A decision based on a stale view must be refused.');
        } catch (InventoryCorrectionConflict $conflict) {
            $this->assertSame(InventoryCorrectionConflict::STALE_CORRECTION, $conflict->reason);
        }

        $this->approve($correction->fresh(), $logistics, ['op' => 'modify', 'fields' => ['model' => 'CF-33']]);
        $this->assertSame('CF-33', $this->item('E1', 'Toughbook')['item']['model']);
    }

    /** H: an offline replay of the same report never makes a duplicate. */
    public function test_h_offline_replay_is_idempotent(): void
    {
        $toughbook = $this->item('E1', 'Toughbook');
        $payload = ['client_submission_id' => (string) Str::uuid(), 'checklist_version' => $this->resolved('E1')['checklist_version'],
            'reason' => 'wrong_quantity', 'compartment_id' => $toughbook['compartment_id'], 'item_id' => $toughbook['item_id'], 'proposal' => ['quantity' => 2]];
        $url = "/api/public/apparatuses/{$this->units['E1']->id}/inventory-corrections";

        $first = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('duplicate', false);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));
        $this->postJson($url, [...$payload, 'proposal' => ['quantity' => 5]])->assertStatus(409)->assertJsonPath('code', 'INVENTORY_CORRECTION_REPLAY_CONFLICT');
        $this->assertSame(1, ApparatusInventoryCorrection::query()->count());
        $this->assertSame(1, ApparatusInventoryCorrectionEvent::query()->where('event_type', 'submitted')->count());
    }

    /** I: two reviewers on one unit; the second, stale approval is refused and nothing is half-applied. */
    public function test_i_concurrent_stale_approval_is_refused(): void
    {
        $passports = $this->item('E1', 'Passports');
        $quantity = $this->reported('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 3]]);
        $move = $this->reported('E1', ['reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['to_compartment_id' => 'rear_cab']]);
        $first = $this->logistics();
        $second = $this->logistics();
        $moveOperation = ['op' => 'modify', 'fields' => ['compartment_id' => 'rear_cab']];
        $stalePlan = $this->service()->preview($move->id, $second, $moveOperation, InventoryChangePlanner::SCOPE_UNIT);

        $this->approve($quantity, $first, ['op' => 'modify', 'fields' => ['expectedQuantity' => 3]]);

        try {
            $this->service()->approve($move->id, $second, $moveOperation, 'unit', [$this->units['E1']->id], $stalePlan['fingerprint'], $move->lock_version, (string) Str::uuid());
            $this->fail('A stale impact preview must be refused.');
        } catch (InventoryCorrectionConflict $conflict) {
            $this->assertSame(InventoryCorrectionConflict::STALE_PREVIEW, $conflict->reason);
        }
        $this->assertSame('submitted', $move->fresh()->status);
        $this->assertSame('front_cab', $this->item('E1', 'Passports')['compartment_id']);

        $this->approve($move->fresh(), $second, $moveOperation);
        $final = $this->item('E1', 'Passports');
        $this->assertSame('rear_cab', $final['compartment_id']);
        $this->assertSame(3, $final['item']['expectedQuantity'], 'both decisions hold; the later one does not undo the earlier');

        // Double approval: the same request replays its result; a new one is refused.
        $set = ApparatusInventoryChangeSet::query()->where('apparatus_inventory_correction_id', $move->id)->sole();
        $this->assertSame($set->id, $this->service()->approve($move->id, $second, $moveOperation, 'unit', [$this->units['E1']->id], 'ignored', null, $set->idempotency_key)->id);
        $this->expectException(ValidationException::class);
        $this->service()->approve($move->id, $second, $moveOperation, 'unit', [$this->units['E1']->id], 'x', null, (string) Str::uuid());
    }

    /** J: a failure part way through a fleet-wide approval leaves everything as it was, with no false success. */
    public function test_j_mid_bulk_failure_leaves_a_consistent_recoverable_state(): void
    {
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 6]]);
        $versions = collect(['E1', 'E3', 'E2'])->mapWithKeys(fn (string $d): array => [$d => $this->resolved($d)['checklist_version']]);
        $logistics = $this->logistics();
        $operation = ['op' => 'modify', 'fields' => ['expectedQuantity' => 6]];
        $plan = $this->service()->preview($correction->id, $logistics, $operation, InventoryChangePlanner::SCOPE_FAMILY);
        $e2 = $this->units['E2']->id;
        ApparatusInventoryChange::creating(static function (ApparatusInventoryChange $change) use ($e2): void {
            if ((int) $change->apparatus_id === $e2) {
                throw new RuntimeException('Simulated failure while applying to Engine 2.');
            }
        });
        Notification::fake();

        try {
            $this->service()->approve($correction->id, $logistics, $operation, 'family',
                collect($plan['units'])->where('default_selected', true)->pluck('apparatus_id')->all(), $plan['fingerprint'], $correction->lock_version, (string) Str::uuid());
            $this->fail('The simulated failure must surface.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Simulated failure', $exception->getMessage());
        } finally {
            ApparatusInventoryChange::flushEventListeners();
        }

        $this->assertSame(0, ApparatusInventoryChange::query()->count());
        $this->assertSame(0, ApparatusInventoryChangeSet::query()->count());
        $this->assertSame(0, ApparatusChecklistRevision::query()->count());
        $fresh = $correction->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame($correction->lock_version, $fresh->lock_version);
        $this->assertSame(0, ApparatusInventoryCorrectionEvent::query()->whereIn('event_type', ['approved', 'applied'])->count());
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->where('event_key', 'like', 'correction:%:update:%')->count());
        Notification::assertNothingSent();
        foreach ($versions as $designation => $version) {
            $this->assertSame($version, $this->resolved($designation)['checklist_version'], $designation);
        }

        // Recoverable: the same decision succeeds once the cause is gone.
        $this->approve($fresh, $logistics, $operation, InventoryChangePlanner::SCOPE_FAMILY);
        $this->assertSame(6, $this->item('E2', 'Passports')['item']['expectedQuantity']);
    }

    public function test_an_inspection_begun_before_an_approval_is_still_accepted_on_the_list_it_used(): void
    {
        $payload = $this->inspectionPayload('E1');
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']]);
        $this->approve($correction, $this->logistics(), ['op' => 'remove']);
        $this->assertNotSame($payload['checklist_version'], $this->resolved('E1')['checklist_version']);

        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $payload)->assertCreated();
        $this->assertSame($payload['checklist_version'], ApparatusInspection::query()->latest('id')->value('checklist_version'));

        $this->travel(8)->days();
        $this->actingAsCanonicalUser($this->member);
        $stale = $this->inspectionPayload('E1');
        $stale['checklist_version'] = $payload['checklist_version'];
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $stale)
            ->assertStatus(409)->assertJsonPath('code', 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED');
    }

    public function test_a_new_item_is_added_to_the_family_and_reported_from_the_inspection(): void
    {
        $draft = (string) Str::uuid();
        $correction = $this->reported('E1', ['reason' => 'new_item', 'compartment_id' => 'rear_cab', 'proposal' => ['name' => 'Thermal Imaging Camera', 'quantity' => 1], 'inspection_draft_id' => $draft]);
        $payload = [...$this->inspectionPayload('E1'), 'inventory_draft_id' => $draft];
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $payload)->assertCreated();
        $this->assertSame(ApparatusInspection::query()->latest('id')->value('id'), $correction->fresh()->apparatus_inspection_id);

        ['plan' => $plan] = $this->approve($correction, $this->logistics(), ['op' => 'add', 'fields' => ['compartment_id' => 'rear_cab', 'name' => 'Thermal Imaging Camera', 'expectedQuantity' => 1]], InventoryChangePlanner::SCOPE_FAMILY);

        foreach (['E1', 'E3', 'E2'] as $designation) {
            $added = $this->item($designation, 'Thermal Imaging Camera');
            $this->assertSame('inv-c'.$correction->id, $added['item_id']);
            $this->assertSame('rear_cab', $added['compartment_id']);
        }
        $this->assertSame('not_daily_checkout', $this->verdictFor($plan, 'E21'));
    }

    public function test_reversal_restores_the_previous_checklist_and_refuses_while_a_later_change_depends_on_it(): void
    {
        $passports = $this->item('E1', 'Passports');
        $original = $this->resolved('E1')['checklist_version'];
        $quantity = $this->reported('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 3]]);
        $logistics = $this->logistics();
        $this->approve($quantity, $logistics, ['op' => 'modify', 'fields' => ['expectedQuantity' => 3]]);
        $rename = $this->reported('E1', ['reason' => 'wrong_description', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['name' => 'Passports (crew)']]);
        $this->approve($rename, $logistics, ['op' => 'modify', 'fields' => ['name' => 'Passports (crew)']]);

        try {
            $this->service()->reverse($quantity->id, $logistics, 'Wrong count.', $quantity->fresh()->lock_version);
            $this->fail('A reversal under a later change must be refused.');
        } catch (InventoryCorrectionConflict $conflict) {
            $this->assertSame(InventoryCorrectionConflict::DEPENDENT_CHANGE, $conflict->reason);
        }

        $this->service()->reverse($rename->id, $logistics, 'Name stays.', $rename->fresh()->lock_version);
        $this->service()->reverse($quantity->id, $logistics, 'Wrong count.', $quantity->fresh()->lock_version);
        $this->assertSame($original, $this->resolved('E1')['checklist_version'], 'exactly the original checklist again');
        $this->assertSame('reversed', $quantity->fresh()->status);
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains($n->title, 'reversed'));
    }
}
