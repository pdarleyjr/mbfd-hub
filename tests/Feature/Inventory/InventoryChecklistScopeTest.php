<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Filament\Resources\InventoryCorrectionResource\Pages\ViewInventoryCorrection;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;
use App\Services\Inventory\InventoryChangePlanner;
use App\Services\Inventory\InventoryCorrectionConflict;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/**
 * The "selected checkout lists" scope: Logistics ticks the checkout lists (each shown with the
 * apparatus using it) and the decision reaches the reporting apparatus plus every apparatus on those lists.
 */
final class InventoryChecklistScopeTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    private const CHECKLISTS = InventoryChangePlanner::SCOPE_CHECKLISTS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
    }

    public function test_usage_lists_every_checkout_list_with_the_apparatus_using_it(): void
    {
        $usage = collect(app(InventoryChangePlanner::class)->checklistUsage())->keyBy('type');

        $this->assertSame(['engine', 'engine2', 'ladder1', 'ladder3', 'rescue'], $usage->keys()->all());
        $this->assertSame(['E1', 'E3', 'E21'], array_column($usage['engine']['units'], 'label'));
        $this->assertSame(['E2'], array_column($usage['engine2']['units'], 'label'));
        $this->assertSame(['R1'], array_column($usage['rescue']['units'], 'label'));
        $this->assertSame('Rescue checklist', $usage['rescue']['label']);
        $e21 = collect($usage['engine']['units'])->firstWhere('label', 'E21');
        $this->assertFalse($e21['daily_checkout'], 'the reserve engine is marked as not on Daily Checkout');
    }

    public function test_the_change_reaches_the_reporting_apparatus_and_only_the_chosen_lists(): void
    {
        $correction = $this->quantityReport();

        ['set' => $set, 'plan' => $plan] = $this->approveChecklists($correction, ['ladder1', 'rescue']);

        $this->assertSame(['ladder1', 'rescue'], $plan['checklist_types']);
        foreach (['E1', 'L1', 'R1'] as $designation) {
            $this->assertSame('eligible', $this->verdictFor($plan, $designation), $designation);
            $this->assertSame(4, $this->item($designation, 'Passports')['item']['expectedQuantity'], $designation);
        }
        foreach (['E3', 'E2', 'L3', 'E21'] as $designation) {
            $this->assertNull($this->verdictFor($plan, $designation), "{$designation} is on a list that was not chosen");
            $this->assertSame(2, $this->item($designation, 'Passports')['item']['expectedQuantity'], $designation);
        }
        $this->assertSame(self::CHECKLISTS, $set->scope);
        $this->assertSame(3, ApparatusInventoryChange::query()->where('apparatus_inventory_correction_id', $correction->id)->count());
        $this->assertSame(['ladder1', 'rescue'], $correction->fresh()->decision['checklist_types']);
    }

    public function test_choosing_the_reporting_apparatus_own_list_reaches_the_other_apparatus_on_it(): void
    {
        $correction = $this->quantityReport();

        ['plan' => $plan] = $this->approveChecklists($correction, ['engine']);

        $this->assertSame('eligible', $this->verdictFor($plan, 'E3'));
        $this->assertSame('not_daily_checkout', $this->verdictFor($plan, 'E21'), 'the reserve is listed but not ticked');
        $this->assertNull($this->verdictFor($plan, 'E2'), 'Engine 2 uses a different checkout list');
        $this->assertSame(4, $this->item('E3', 'Passports')['item']['expectedQuantity']);
        $this->assertSame(2, $this->item('E21', 'Passports')['item']['expectedQuantity']);
        $this->assertSame(2, $this->item('E2', 'Passports')['item']['expectedQuantity']);
    }

    public function test_a_reserve_can_be_included_on_purpose(): void
    {
        $correction = $this->quantityReport();

        $this->approveChecklists($correction, ['engine'], ['E1', 'E3', 'E21']);

        $this->assertSame(4, $this->item('E21', 'Passports')['item']['expectedQuantity']);
    }

    public function test_at_least_one_known_list_is_required(): void
    {
        $correction = $this->quantityReport();
        $logistics = $this->logistics();

        foreach ([[], ['no-such-list'], ['pending']] as $types) {
            try {
                $this->service()->preview($correction->id, $logistics, $this->operation(), self::CHECKLISTS, $types);
                $this->fail('A scope with no usable checkout list must be refused: '.json_encode($types));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('checklist_types', $exception->errors());
            }
        }
    }

    public function test_the_chosen_lists_are_part_of_the_preview_the_reviewer_approved(): void
    {
        $correction = $this->quantityReport();
        $logistics = $this->logistics();
        $plan = $this->service()->preview($correction->id, $logistics, $this->operation(), self::CHECKLISTS, ['rescue']);

        $this->expectException(InventoryCorrectionConflict::class);
        $this->service()->approve(
            $correction->id, $logistics, $this->operation(), self::CHECKLISTS,
            [$this->units['E1']->id, $this->units['R1']->id, $this->units['L1']->id],
            $plan['fingerprint'], $correction->lock_version, (string) Str::uuid(),
            checklistTypes: ['rescue', 'ladder1'],
        );
    }

    public function test_the_scope_needs_the_same_authority_as_the_other_fleet_wide_scopes(): void
    {
        $correction = $this->quantityReport();

        $this->expectException(AuthorizationException::class);
        $this->service()->preview($correction->id, $this->fleetOnlyReviewer(), $this->operation(), self::CHECKLISTS, ['rescue']);
    }

    public function test_reversing_returns_every_apparatus_the_decision_reached(): void
    {
        $correction = $this->quantityReport();
        $logistics = $this->logistics();
        $before = collect(['E1', 'L1', 'R1'])->mapWithKeys(fn (string $designation): array => [$designation => $this->item($designation, 'Passports')['item']['expectedQuantity'] ?? null]);
        $this->approveChecklists($correction, ['ladder1', 'rescue']);
        $this->assertSame(4, $this->item('R1', 'Passports')['item']['expectedQuantity']);

        $this->service()->reverse($correction->id, $logistics, 'Entered by mistake.', $correction->fresh()->lock_version);

        foreach ($before as $designation => $quantity) {
            $this->assertSame($quantity, $this->item($designation, 'Passports')['item']['expectedQuantity'] ?? null, $designation);
        }
    }

    public function test_the_approval_form_offers_the_lists_with_their_apparatus_and_applies_the_choice(): void
    {
        $correction = $this->quantityReport();
        $this->actingAs($this->logistics());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewInventoryCorrection::class, ['record' => $correction->getRouteKey()])
            ->mountAction('approve')
            ->assertDontSee('Also apply to these checkout lists')
            ->setActionData(['op' => 'modify', 'expectedQuantity' => 4, 'scope' => self::CHECKLISTS, 'checklist_types' => ['ladder1', 'rescue']])
            ->assertSee('Also apply to these checkout lists')
            ->assertSee('Used by: R1')
            ->assertSee('Used by: E1, E3, E21 (not on Daily Checkout)')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('applied', $correction->fresh()->status);
        foreach (['E1', 'L1', 'R1'] as $designation) {
            $this->assertSame(4, $this->item($designation, 'Passports')['item']['expectedQuantity'], $designation);
        }
        $this->assertSame(2, $this->item('E3', 'Passports')['item']['expectedQuantity']);
    }

    /** @return array{op: string, fields: array<string, mixed>} */
    private function operation(): array
    {
        return ['op' => 'modify', 'fields' => ['expectedQuantity' => 4]];
    }

    private function quantityReport(): ApparatusInventoryCorrection
    {
        $passports = $this->item('E1', 'Passports');

        return $this->reported('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 4]]);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $designations  defaults to what the preview ticks
     * @return array{set: \App\Models\ApparatusInventoryChangeSet, plan: array<string, mixed>}
     */
    private function approveChecklists(ApparatusInventoryCorrection $correction, array $types, ?array $designations = null): array
    {
        $logistics = $this->logistics();
        $plan = $this->service()->preview($correction->id, $logistics, $this->operation(), self::CHECKLISTS, $types);
        $ids = $designations === null
            ? collect($plan['units'])->where('default_selected', true)->pluck('apparatus_id')->all()
            : array_map(fn (string $designation): int => $this->units[$designation]->id, $designations);
        $set = $this->service()->approve(
            $correction->id, $logistics, $this->operation(), self::CHECKLISTS, $ids,
            $plan['fingerprint'], $correction->fresh()->lock_version, (string) Str::uuid(),
            checklistTypes: $types,
        );

        return ['set' => $set, 'plan' => $plan];
    }
}
