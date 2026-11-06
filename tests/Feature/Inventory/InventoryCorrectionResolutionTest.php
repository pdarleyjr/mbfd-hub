<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Filament\Employee\Pages\MyInventoryCorrectionView;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Filament\Resources\InventoryCorrectionResource\Pages\ListInventoryCorrections;
use App\Filament\Resources\InventoryCorrectionResource\Pages\ViewInventoryCorrection;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionConflict;
use App\Services\Inventory\InventoryOverlayHealth;
use App\Support\Dashboards\IssueSourceRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/**
 * Every inventory correction reaches a final state through a button someone can press: approved
 * (then possibly reversed), rejected, closed with no change needed, withdrawn by the member, or
 * combined into another request. Approved changes that stop applying are visible and can be retired.
 */
final class InventoryCorrectionResolutionTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    private User $logistics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
        $this->logistics = $this->logistics();
    }

    private function passports(string $reason, array $proposal = [], string $unit = 'E1'): ApparatusInventoryCorrection
    {
        $passports = $this->item($unit, 'Passports');

        return $this->reported($unit, ['reason' => $reason, 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], ...($proposal !== [] ? ['proposal' => $proposal] : [])]);
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->logistics);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function asMember(): void
    {
        $this->actingAsCanonicalUser($this->member);
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->bindCanonicalSessionToLivewireTestRequests();
    }

    public function test_a_request_already_made_moot_is_closed_without_a_rejection(): void
    {
        $remove = $this->passports('no_longer_carried');
        $quantity = $this->passports('wrong_quantity', ['quantity' => 4]);
        $this->approve($remove, $this->logistics, ['op' => 'remove']);
        $this->asAdmin();

        // The quantity request can no longer be approved as reported: the page says so and offers the closure.
        $this->get(InventoryCorrectionResource::getUrl('view', ['record' => $quantity]))
            ->assertOk()
            ->assertSee('Cannot be approved as reported')
            ->assertSee('Close, no change needed');
        Livewire::test(ViewInventoryCorrection::class, ['record' => $quantity->getRouteKey()])
            ->mountAction('closeNoChange')
            ->assertActionDataSet(['message' => 'This item is no longer on the checklist for this apparatus, so no change was needed.'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionHidden('approve')
            ->assertActionHidden('reject')
            ->assertActionHidden('closeNoChange');

        $quantity->refresh();
        $this->assertSame(ApparatusInventoryCorrection::STATUS_CLOSED, $quantity->status);
        $this->assertNotNull($quantity->decided_at);
        $this->assertSame((int) $this->logistics->id, (int) $quantity->decided_by_user_id);
        $this->assertSame(1, $quantity->events()->where('event_type', Event::CLOSED)->where('visibility', Event::VISIBILITY_MEMBER)->count());

        // The member reads a closure, not a rejection, and can no longer write into a thread nobody reviews.
        $this->asMember();
        Livewire::test(MyInventoryCorrectionView::class, ['correction' => $quantity->id])
            ->assertSee('Closed, no change needed')
            ->assertSee('This is not a rejection.')
            ->assertSee('replies are off')
            ->assertDontSee('Send reply');
        $this->expectException(ValidationException::class);
        $this->service()->postMessage($quantity->id, $this->member, 'Still wrong?', Event::VISIBILITY_MEMBER);
    }

    public function test_the_member_who_filed_a_request_can_withdraw_it_once(): void
    {
        $correction = $this->passports('wrong_quantity', ['quantity' => 4]);
        $this->asMember();

        Livewire::test(MyInventoryCorrectionView::class, ['correction' => $correction->id])
            ->assertSee('Withdraw this request')
            ->set('withdrawNote', 'Counted again: it was right.')
            ->call('withdraw')
            ->assertHasNoErrors()
            ->assertSee('You withdrew this request')
            ->assertDontSee('Withdraw request');

        $correction->refresh();
        $this->assertSame(ApparatusInventoryCorrection::STATUS_WITHDRAWN, $correction->status);
        $this->assertFalse($correction->isOpen());
        $withdrawn = $correction->events()->where('event_type', Event::WITHDRAWN)->sole();
        $this->assertSame('Counted again: it was right.', $withdrawn->body);
        $this->assertSame('member', $withdrawn->author_context);
        $this->assertStringContainsString('already withdrawn', (string) $this->service()->withdrawRefusal($correction, $this->member));

        // Logistics sees it closed; nothing is left to decide.
        $this->asAdmin();
        Livewire::test(ViewInventoryCorrection::class, ['record' => $correction->getRouteKey()])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject')
            ->assertSee('Withdrawn by the member');
    }

    public function test_a_request_other_members_joined_cannot_be_withdrawn_by_one_of_them(): void
    {
        $correction = $this->passports('wrong_quantity', ['quantity' => 4]);
        $other = $this->actingAsCanonicalFixture('INV-009', 'Other Member', 'Firefighter');
        $passports = $this->item('E1', 'Passports');
        $this->report('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 4]])
            ->assertOk()->assertJsonPath('joined_existing', true);

        $this->assertStringContainsString('Other members reported the same change', (string) $this->service()->withdrawRefusal($correction->fresh(), $this->member));
        $this->assertStringContainsString('Only the member who filed', (string) $this->service()->withdrawRefusal($correction->fresh(), $other));
        try {
            $this->service()->withdraw($correction->id, $this->member);
            $this->fail('A shared request must stay with Logistics.');
        } catch (ValidationException) {
            $this->assertSame(ApparatusInventoryCorrection::STATUS_SUBMITTED, $correction->fresh()->status);
        }

        $this->asMember();
        Livewire::test(MyInventoryCorrectionView::class, ['correction' => $correction->id])
            ->assertDontSee('Withdraw request')
            ->assertSee('Other members reported the same change');
    }

    public function test_an_approved_change_that_stops_applying_is_shown_and_can_be_retired(): void
    {
        $correction = $this->passports('wrong_quantity', ['quantity' => 4]);
        $this->approve($correction, $this->logistics, ['op' => 'modify', 'fields' => ['expectedQuantity' => 4]]);
        $applied = $correction->changes()->sole();
        // A template change under an approved change: here, a change whose item the checklist no longer has.
        $goneItem = $applied->item_key.'-gone';
        $ghost = ApparatusInventoryChange::query()->create([
            ...collect($applied->getAttributes())->except(['id', 'created_at', 'updated_at', 'payload', 'before', 'item_key'])->all(),
            'item_key' => $goneItem,
            'payload' => ['expectedQuantity' => 2],
            'before' => [],
        ]);
        Cache::flush();
        $before = $this->resolved('E1');
        $this->assertSame([['change_id' => $ghost->id, 'reason' => 'item_not_found']], $before['overlay_conflicts']);
        $this->assertSame([$ghost->id => 'item_not_found'], app(InventoryOverlayHealth::class)->conflictsFor([$applied, $ghost]));

        $this->asAdmin();
        $this->assertStringContainsString('1 approved change is not applying to its checklist.', (string) Livewire::test(ListInventoryCorrections::class)->instance()->getSubheading()?->toHtml());
        $this->get(InventoryCorrectionResource::getUrl('view', ['record' => $correction]))
            ->assertOk()
            ->assertSee('Not applied to the checklist')
            ->assertSee('Retire change #'.$ghost->id);

        // A change that still applies cannot be retired (reverse its correction instead).
        try {
            $this->service()->retireChange($applied->id, $this->logistics);
            $this->fail('Only a change that does not apply can be retired.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('still applies', (string) collect($exception->errors())->flatten()->first());
        }

        Livewire::test(ViewInventoryCorrection::class, ['record' => $correction->getRouteKey()])
            ->callAction('retireChange', ['note' => 'Template replaced this item.'], ['change' => $ghost->id])
            ->assertHasNoActionErrors();

        $this->assertSame(ApparatusInventoryChange::STATUS_RETIRED, $ghost->fresh()->status);
        $this->assertSame(ApparatusInventoryChange::STATUS_ACTIVE, $applied->fresh()->status);
        $after = $this->resolved('E1');
        $this->assertSame($before['checklist_version'], $after['checklist_version'], 'retiring never changes the checklist');
        $this->assertSame([], $after['overlay_conflicts']);
        $retired = $correction->events()->where('event_type', Event::CHANGE_RETIRED)->sole();
        $this->assertSame(Event::VISIBILITY_INTERNAL, $retired->visibility);
        $this->assertSame($ghost->id, $retired->details['change_id']);
        $this->assertSame(ApparatusInventoryCorrection::STATUS_APPLIED, $correction->fresh()->status);

        // A retired change is already without effect: reversing its correction still works.
        $this->service()->reverse($correction->id, $this->logistics, 'Back to the template.', $correction->fresh()->lock_version);
        $this->assertSame(ApparatusInventoryChange::STATUS_RETIRED, $ghost->fresh()->status);
        $this->assertSame(ApparatusInventoryChange::STATUS_REVERSED, $applied->fresh()->status);
    }

    public function test_a_blocked_reversal_names_the_request_to_reverse_first(): void
    {
        $rename = $this->passports('wrong_description', ['name' => 'Passport Binder']);
        $this->approve($rename, $this->logistics, ['op' => 'modify', 'fields' => ['name' => 'Passport Binder']]);
        $remove = $this->reported('E1', ['reason' => 'no_longer_carried', 'compartment_id' => $this->item('E1', 'Passport Binder')['compartment_id'], 'item_id' => $this->item('E1', 'Passport Binder')['item_id']]);
        $this->approve($remove, $this->logistics, ['op' => 'remove']);

        try {
            $this->service()->reverse($rename->id, $this->logistics, 'Undo the rename.', $rename->fresh()->lock_version);
            $this->fail('A later change builds on the rename.');
        } catch (InventoryCorrectionConflict $conflict) {
            $this->assertSame(InventoryCorrectionConflict::DEPENDENT_CHANGE, $conflict->reason);
            $this->assertSame($remove->id, $conflict->relatedCorrectionId);
            $this->assertStringContainsString('Reverse '.$remove->fresh()->reference().' first', $conflict->getMessage());
        }

        // Reversing in the order the message gives works.
        $this->service()->reverse($remove->id, $this->logistics, 'Still carried.', $remove->fresh()->lock_version);
        $this->service()->reverse($rename->id, $this->logistics, 'Undo the rename.', $rename->fresh()->lock_version);
        $this->assertSame('Passports', $this->item('E1', 'Passports')['item']['name']);
    }

    public function test_the_dashboard_closes_requests_and_offers_nothing_the_service_would_refuse(): void
    {
        $source = app(IssueSourceRegistry::class)->get('inventory_correction');
        $correction = $this->passports('wrong_quantity', ['quantity' => 4]);
        $detail = $source->detail($this->logistics, (string) $correction->id);
        $this->assertContains('close_no_change', array_map(static fn ($action): string => $action->key, $detail->actions));

        $result = $source->perform($this->logistics, (string) $correction->id, 'close_no_change', ['message' => 'Already four on the truck.'], $detail->issue->version);
        $this->assertSame('done', $result->outcome);
        $this->assertSame(ApparatusInventoryCorrection::STATUS_CLOSED, $correction->fresh()->status);
        $this->assertSame([], array_values(array_filter(
            array_map(static fn ($action): string => $action->key, $source->detail($this->logistics, (string) $correction->id)->actions),
            static fn (string $key): bool => $key !== 'reply',
        )));

        // A multi-apparatus change: a Fleet-only reviewer is not offered a reversal the service would refuse.
        $family = $this->passports('wrong_quantity', ['quantity' => 5]);
        $this->approve($family, $this->logistics, ['op' => 'modify', 'fields' => ['expectedQuantity' => 5]], 'family');
        $fleetOnly = $this->fleetOnlyReviewer();
        $keys = array_map(static fn ($action): string => $action->key, $source->detail($fleetOnly, (string) $family->id)->actions);
        $this->assertNotContains('reverse', $keys);
        $this->assertContains('reverse', array_map(static fn ($action): string => $action->key, $source->detail($this->logistics, (string) $family->id)->actions));
    }

    public function test_every_status_has_a_label_and_the_queue_tabs_cover_every_status(): void
    {
        foreach ([...ApparatusInventoryCorrection::OPEN_STATUSES, ApparatusInventoryCorrection::STATUS_APPLIED, ApparatusInventoryCorrection::STATUS_REVERSED, ...ApparatusInventoryCorrection::CLOSED_WITHOUT_CHANGE_STATUSES] as $status) {
            $this->assertNotSame('Unknown', ApparatusInventoryCorrection::statusLabel($status), $status);
        }
        $this->assertEqualsCanonicalizing(
            array_keys(ApparatusInventoryCorrection::statusLabels()),
            [...ApparatusInventoryCorrection::OPEN_STATUSES, ApparatusInventoryCorrection::STATUS_APPLIED, ApparatusInventoryCorrection::STATUS_REVERSED, ...ApparatusInventoryCorrection::CLOSED_WITHOUT_CHANGE_STATUSES],
            'every status is open, approved, reversed or closed without a change',
        );

        $withdrawn = $this->passports('wrong_quantity', ['quantity' => 4]);
        $this->service()->withdraw($withdrawn->id, $this->member, null, (string) Str::uuid());
        $open = $this->passports('no_longer_carried');
        $this->asAdmin();
        Livewire::test(ListInventoryCorrections::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$withdrawn])
            ->set('activeTab', 'closed')
            ->assertCanSeeTableRecords([$withdrawn])
            ->assertCanNotSeeTableRecords([$open]);
    }
}
