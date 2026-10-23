<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AccountStatus;
use App\Filament\Employee\Pages\MyInventoryCorrectionView;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/** Authorization, tampering and validation for inventory corrections; every refusal leaves state unchanged. */
final class InventoryCorrectionSecurityTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
    }

    public function test_reporting_requires_a_signed_in_member(): void
    {
        $passports = $this->item('E1', 'Passports');
        $version = $this->resolved('E1')['checklist_version'];
        $this->logoutCanonicalSession();
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inventory-corrections", [
            'client_submission_id' => (string) Str::uuid(), 'checklist_version' => $version,
            'reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'],
        ])->assertUnauthorized();
        $this->assertSame(0, ApparatusInventoryCorrection::query()->count());
    }

    public function test_reports_must_point_at_the_checklist_the_member_saw(): void
    {
        $passports = $this->item('E1', 'Passports');
        $this->report('E1', ['reason' => 'no_longer_carried', 'compartment_id' => 'not_a_compartment', 'item_id' => $passports['item_id']])->assertUnprocessable();
        $this->report('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => 'forged-item'])->assertUnprocessable();
        $this->report('E1', ['reason' => 'no_longer_carried', 'compartment_id' => 'rear_cab', 'item_id' => $passports['item_id']])->assertUnprocessable();
        $this->report('E1', ['reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['to_compartment_id' => 'ladder_only']])->assertUnprocessable();
        $this->report('E1', ['reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']])->assertUnprocessable();
        $this->report('E1', ['reason' => 'new_item', 'compartment_id' => 'rear_cab', 'item_id' => $passports['item_id'], 'proposal' => ['name' => 'X']])->assertUnprocessable();
        $this->report('E1', ['reason' => 'other', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']])->assertUnprocessable();
        $this->report('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 0]])->assertUnprocessable();
        $this->report('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'checklist_version' => str_repeat('a', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'INVENTORY_CORRECTION_CHECKLIST_CHANGED');
        $this->report('E1', ['reason' => 'replaced', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'photo' => 'data:image/png;base64,bm90IGFuIGltYWdl'])->assertUnprocessable();
        $this->assertSame(0, ApparatusInventoryCorrection::query()->count());
    }

    public function test_members_cannot_decide_or_read_other_members_corrections(): void
    {
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']]);
        $other = $this->actingAsCanonicalFixture('INV-002', 'Other Member', 'Firefighter');

        $this->assertRefused(fn () => $this->service()->postMessage($correction->id, $other, 'Hijack', 'member'), AuthorizationException::class);
        $this->assertRefused(fn () => $this->service()->reject($correction->id, $this->member, 'no'), AuthorizationException::class);
        $this->assertRefused(fn () => $this->service()->requestClarification($correction->id, $this->member, 'why'), AuthorizationException::class);
        $this->assertRefused(fn () => $this->service()->preview($correction->id, $this->member, ['op' => 'remove'], 'unit'), AuthorizationException::class);
        $this->assertRefused(fn () => $this->service()->approve($correction->id, $this->member, ['op' => 'remove'], 'unit', [$this->units['E1']->id], 'x', null, (string) Str::uuid()), AuthorizationException::class);

        $this->get(MyInventoryCorrectionView::getUrl(['correction' => $correction->id], panel: 'employee'))->assertForbidden();
        $this->get(route('inventory-corrections.photo', $correction))->assertForbidden();
        $this->get(InventoryCorrectionResource::getUrl('view', ['record' => $correction->id], panel: 'admin'))->assertForbidden();
        $this->assertSame('submitted', $correction->fresh()->status);
        $this->assertSame(0, ApparatusInventoryChange::query()->count());
    }

    public function test_the_reporter_sees_their_correction_without_internal_notes(): void
    {
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']]);
        $logistics = $this->logistics();
        $this->service()->postMessage($correction->id, $logistics, 'Confidential: vendor recall pending.', 'internal');
        $this->service()->requestClarification($correction->id, $logistics, 'Where did it go?', $correction->lock_version);

        $this->actingAsCanonicalUser($this->member);
        $this->get(MyInventoryCorrectionView::getUrl(['correction' => $correction->id], panel: 'employee'))
            ->assertOk()
            ->assertSee('Where did it go?')
            ->assertDontSee('vendor recall');
        $this->getJson("/api/public/apparatuses/{$this->units['E1']->id}/checklist")->assertOk()
            ->assertJsonMissingPath('pending_corrections.0.reporter_user_id')
            ->assertJsonPath('pending_corrections.0.status', 'clarification_requested');
    }

    public function test_fleet_only_reviewers_change_one_apparatus_and_tampered_selections_are_refused(): void
    {
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 3]]);
        $operation = ['op' => 'modify', 'fields' => ['expectedQuantity' => 3]];
        $fleet = $this->fleetOnlyReviewer();
        $this->assertRefused(fn () => $this->service()->preview($correction->id, $fleet, $operation, 'family'), AuthorizationException::class);

        $logistics = $this->logistics();
        $plan = $this->service()->preview($correction->id, $logistics, $operation, 'family');
        $key = (string) Str::uuid();
        // A ladder is not in the engine family; a unit-scope decision cannot reach a second unit; the source is required.
        $this->assertRefused(fn () => $this->service()->approve($correction->id, $logistics, $operation, 'family', [$this->units['E1']->id, $this->units['L1']->id], $plan['fingerprint'], 0, $key), ValidationException::class);
        $unitPlan = $this->service()->preview($correction->id, $logistics, $operation, 'unit');
        $this->assertRefused(fn () => $this->service()->approve($correction->id, $logistics, $operation, 'unit', [$this->units['E1']->id, $this->units['E3']->id], $unitPlan['fingerprint'], 0, (string) Str::uuid()), ValidationException::class);
        $this->assertRefused(fn () => $this->service()->approve($correction->id, $logistics, $operation, 'family', [$this->units['E3']->id], $plan['fingerprint'], 0, (string) Str::uuid()), ValidationException::class);
        $this->assertRefused(fn () => $this->service()->approve($correction->id, $logistics, $operation, 'galaxy', [$this->units['E1']->id], $plan['fingerprint'], 0, (string) Str::uuid()), ValidationException::class);
        $this->assertSame(0, ApparatusInventoryChange::query()->count());

        $this->service()->approve($correction->id, $fleet, $operation, 'unit', [$this->units['E1']->id], $unitPlan['fingerprint'], 0, (string) Str::uuid());
        $this->assertSame(3, $this->item('E1', 'Passports')['item']['expectedQuantity']);
        $this->assertSame(2, $this->item('E3', 'Passports')['item']['expectedQuantity']);
    }

    public function test_bad_destinations_and_ambiguous_identity_are_excluded_not_guessed(): void
    {
        $logistics = $this->logistics();
        $pikePole = $this->item('E2', 'Small Pike Pole');
        $correction = $this->reported('E2', ['reason' => 'wrong_quantity', 'compartment_id' => $pikePole['compartment_id'], 'item_id' => $pikePole['item_id'], 'proposal' => ['quantity' => 2]]);

        $plan = $this->service()->preview($correction->id, $logistics, ['op' => 'modify', 'fields' => ['expectedQuantity' => 2]], 'family');
        $this->assertSame('ambiguous_item', $this->verdictFor($plan, 'E1'), 'Engine 1 carries two small pike poles');
        $this->assertFalse(collect($plan['units'])->firstWhere('apparatus_id', $this->units['E1']->id)['includable']);

        $missing = $this->service()->preview($correction->id, $logistics, ['op' => 'modify', 'fields' => ['compartment_id' => 'no_such_compartment']], 'unit');
        $this->assertSame('destination_missing', $this->verdictFor($missing, 'E2'));
        $this->assertRefused(fn () => $this->service()->approve($correction->id, $logistics, ['op' => 'modify', 'fields' => ['compartment_id' => 'no_such_compartment']], 'unit', [$this->units['E2']->id], $missing['fingerprint'], 0, (string) Str::uuid()), ValidationException::class);

        $clash = $this->item('E2', 'Toughbook');
        $rename = $this->service()->preview($correction->id, $logistics, ['op' => 'modify', 'fields' => ['name' => $clash['item']['name'], 'compartment_id' => $clash['compartment_id']]], 'unit');
        $this->assertSame('invalid_result', $this->verdictFor($rename, 'E2'), 'two items with one name in a compartment would make the checklist ambiguous');
        $this->assertSame(0, ApparatusInventoryChange::query()->count());
    }

    public function test_inactive_accounts_cannot_review(): void
    {
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id']]);
        $suspended = $this->logistics();
        $suspended->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $this->assertRefused(fn () => $this->service()->reject($correction->id, $suspended->fresh(), 'no'), AuthorizationException::class);
        $this->assertSame('submitted', $correction->fresh()->status);
    }

    /** @param class-string<\Throwable> $exception */
    private function assertRefused(callable $attempt, string $exception): void
    {
        try {
            $attempt();
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown, $thrown->getMessage());

            return;
        }
        $this->fail("Expected {$exception}.");
    }
}
