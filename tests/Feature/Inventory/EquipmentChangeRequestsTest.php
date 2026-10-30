<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AccountStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Filament\Resources\InventoryCorrectionResource\Pages\ViewInventoryCorrection;
use App\Filament\Widgets\EquipmentChangeRequestsWidget;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryChangeSet;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind as Kind;
use App\Services\Inventory\InventoryChangePlanner;
use App\Services\Inventory\InventoryCorrectionConflict;
use App\Services\Inventory\InventoryCorrectionDiff;
use App\Services\Inventory\InventoryCorrectionProjection;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Equipment change requests, end to end: a member proposes, the authoritative inventory waits,
 * an administrator decides once, and only the reported apparatus changes
 * (docs/apparatus-inventory-corrections.md, section 7).
 */
final class EquipmentChangeRequestsTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
    }

    /** @return array{reason: string, compartment_id: string, item_id: string, proposal: array<string, mixed>} */
    private function passportsMove(string $unit, string $to): array
    {
        $passports = $this->item($unit, 'Passports');

        return ['reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['to_compartment_id' => $to]];
    }

    private function asReviewer(User $reviewer): void
    {
        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_member_request_waits_for_approval_with_what_a_reviewer_needs_and_changes_nothing(): void
    {
        $reviewer = $this->logistics();
        $versionBefore = $this->resolved('E1')['checklist_version'];
        $passports = $this->item('E1', 'Passports');

        $response = $this->report('E1', [...$this->passportsMove('E1', 'rear_cab'), 'note' => 'Now behind the officer seat.'])
            ->assertCreated()
            ->assertJsonPath('status', 'submitted');

        $correction = ApparatusInventoryCorrection::query()->findOrFail($response->json('id'));
        $this->assertSame((int) $this->units['E1']->id, (int) $correction->apparatus_id, 'apparatus id');
        $this->assertSame($passports['item_id'], $correction->item_key, 'equipment id');
        $this->assertSame('name:passports', $correction->equipment_key);
        $this->assertSame('Front Cab', $correction->target_snapshot['compartment'], 'old value');
        $this->assertSame('Rear Cab', $correction->target_snapshot['to_compartment'], 'proposed compartment, by name');
        $this->assertSame(['to_compartment_id' => 'rear_cab'], $correction->proposal, 'proposed value');
        $this->assertSame((int) $this->member->id, (int) $correction->reporter_user_id, 'submitter');
        $this->assertSame('Now behind the officer seat.', $correction->member_note, 'optional reason');
        $this->assertSame('Pending approval', ApparatusInventoryCorrection::statusLabel($correction->status));
        $this->assertSame([['label' => 'Compartment', 'before' => 'Front Cab', 'after' => 'Rear Cab']], InventoryCorrectionDiff::rows($correction));

        // The authoritative record has not moved.
        $this->assertSame($versionBefore, $this->resolved('E1')['checklist_version']);
        $this->assertSame('front_cab', $this->item('E1', 'Passports')['compartment_id']);
        $this->assertSame(0, ApparatusInventoryChange::query()->count());
        $this->assertSame(0, ApparatusInventoryChangeSet::query()->count());

        // The reviewers are told, and the notification opens this exact request.
        Notification::assertSentTo($reviewer, InspectionEventNotification::class, fn (InspectionEventNotification $notification): bool => $notification->kind === Kind::INVENTORY_CORRECTION_SUBMITTED
            && str_contains($notification->url, '/inventory-corrections/'.$correction->getKey()));
        Notification::assertNotSentTo($this->member, InspectionEventNotification::class);
    }

    /**
     * Regression. The old submit path answered a second, different request about the same item with
     * the FIRST open report ("duplicate"), so the member's changed proposal was never recorded: the
     * member believed it was sent, Logistics reviewed the stale one. A changed request is a new request.
     */
    public function test_a_changed_request_is_never_dropped_in_favour_of_the_earlier_one(): void
    {
        $first = $this->report('E1', $this->passportsMove('E1', 'rear_cab'))->assertCreated();

        // A second tap or another device saying exactly the same thing still merges.
        $this->report('E1', $this->passportsMove('E1', 'rear_cab'))
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));

        // Changing their mind is a different request and must reach Logistics.
        $second = $this->report('E1', $this->passportsMove('E1', 'comp_a1'))->assertCreated()->assertJsonPath('duplicate', false);
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame(['to_compartment_id' => 'comp_a1'], ApparatusInventoryCorrection::query()->findOrFail($second->json('id'))->proposal);
        $this->assertSame(['to_compartment_id' => 'rear_cab'], ApparatusInventoryCorrection::query()->findOrFail($first->json('id'))->proposal, 'the earlier request is untouched');
        $this->assertSame(2, ApparatusInventoryCorrection::query()->open()->count());
    }

    /**
     * Regression. Rejecting needed a typed reason (the service took a non-null string), so a quick
     * "no" was impossible and a bare rejection could not tell the member anything.
     */
    public function test_a_rejection_message_is_optional_and_a_bare_rejection_still_tells_the_member(): void
    {
        $reviewer = $this->logistics();
        $before = $this->resolved('E1')['checklist_version'];
        $correction = $this->reported('E1', $this->passportsMove('E1', 'rear_cab'));

        $this->service()->reject($correction->id, $reviewer, null, null, $correction->lock_version, $key = (string) Str::uuid());

        $rejected = $correction->fresh();
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Rejected', ApparatusInventoryCorrection::statusLabel($rejected->status));
        $this->assertNull($rejected->decision_reason);
        $this->assertSame($reviewer->id, (int) $rejected->decided_by_user_id);
        $this->assertSame($before, $this->resolved('E1')['checklist_version'], 'the inventory is unchanged');
        $this->assertSame(0, ApparatusInventoryChange::query()->count());
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $notification): bool => $notification->kind === Kind::INVENTORY_CORRECTION_UPDATE
            && str_contains($notification->title, 'rejected') && str_contains($notification->body, 'was not changed'));

        // The member sees it as Rejected, with the history intact and no invented message.
        $detail = app(InventoryCorrectionProjection::class)->memberDetail($rejected);
        $this->assertSame('Rejected', $detail['status_label']);
        $this->assertNull($detail['decision_reason']);

        // A repeat click with the same key returns the first result; a second admin's click is refused.
        $this->assertSame('rejected', $this->service()->reject($correction->id, $reviewer, null, null, null, $key)->status);
        $this->assertSame(1, ApparatusInventoryCorrectionEvent::query()->where('apparatus_inventory_correction_id', $correction->id)->where('event_type', 'rejected')->count());
        $this->expectException(ValidationException::class);
        $this->service()->reject($correction->id, $this->logistics(), 'Too late', null, null, (string) Str::uuid());
    }

    public function test_the_review_page_rejects_without_a_message(): void
    {
        $correction = $this->reported('E1', $this->passportsMove('E1', 'rear_cab'));
        $this->asReviewer($this->logistics());

        Livewire::test(ViewInventoryCorrection::class, ['record' => $correction->getRouteKey()])
            ->assertSee('What would change')
            ->assertSee('Apparatus ID')
            ->assertSee('Equipment ID')
            ->assertSee('Front Cab')
            ->assertSee('Rear Cab')
            ->callAction('reject', [])
            ->assertHasNoActionErrors();

        $this->assertSame('rejected', $correction->fresh()->status);
        $this->assertSame('front_cab', $this->item('E1', 'Passports')['compartment_id']);
    }

    public function test_two_administrators_cannot_both_decide_one_request_and_a_stale_page_is_refused(): void
    {
        $first = $this->logistics();
        $second = $this->logistics();
        $operation = ['op' => 'modify', 'fields' => ['compartment_id' => 'rear_cab']];

        // The second administrator's page is already open on this version when the first one rejects.
        $rejectedCorrection = $this->reported('E1', $this->passportsMove('E1', 'rear_cab'));
        $stalePlan = $this->service()->preview($rejectedCorrection->id, $second, $operation, InventoryChangePlanner::SCOPE_UNIT);
        $this->service()->reject($rejectedCorrection->id, $first, 'Stays in the front cab.', null, 0, (string) Str::uuid());
        try {
            $this->service()->approve($rejectedCorrection->id, $second, $operation, 'unit', [$this->units['E1']->id], $stalePlan['fingerprint'], 0, (string) Str::uuid());
            $this->fail('A decision made after another administrator rejected the request must be refused.');
        } catch (ValidationException) {
            // already decided
        }
        $this->assertSame('rejected', $rejectedCorrection->fresh()->status);
        $this->assertSame(0, ApparatusInventoryChange::query()->count());
        $this->assertSame('front_cab', $this->item('E1', 'Passports')['compartment_id']);

        // The first administrator approves; the second one's reject is refused and exactly one change exists.
        $this->actingAsCanonicalUser($this->member);
        $approved = $this->reported('E3', $this->passportsMove('E3', 'rear_cab'));
        $this->approve($approved, $first, $operation);
        try {
            $this->service()->reject($approved->id, $second, 'No', null, 0, (string) Str::uuid());
            $this->fail('A rejection after an approval must be refused.');
        } catch (ValidationException) {
            // already decided
        }
        $this->assertSame('applied', $approved->fresh()->status);
        $this->assertSame(1, ApparatusInventoryChangeSet::query()->count());
        $this->assertSame(1, ApparatusInventoryChange::query()->count());
        $this->assertSame('rear_cab', $this->item('E3', 'Passports')['compartment_id']);
        $this->assertSame('front_cab', $this->item('E1', 'Passports')['compartment_id'], 'the rejected request on another unit changed nothing');

        // A stale page (opened before a question and its answer moved the request on) is refused; the fresh one works once.
        $this->actingAsCanonicalUser($this->member);
        $request = $this->reported('E1', $this->passportsMove('E1', 'rear_cab'));
        $this->service()->requestClarification($request->id, $second, 'Which side?', 0);
        $this->service()->postMessage($request->id, $this->member, 'Driver side.', 'member', (string) Str::uuid());
        $this->assertSame(2, (int) $request->fresh()->lock_version);
        $plan = $this->service()->preview($request->id, $first, $operation, InventoryChangePlanner::SCOPE_UNIT);
        try {
            $this->service()->approve($request->id, $first, $operation, 'unit', [$this->units['E1']->id], $plan['fingerprint'], 0, (string) Str::uuid());
            $this->fail('A page opened at lock 0 must be refused at lock 2.');
        } catch (InventoryCorrectionConflict $conflict) {
            $this->assertSame(InventoryCorrectionConflict::STALE_CORRECTION, $conflict->reason);
        }
        $this->assertSame('front_cab', $this->item('E1', 'Passports')['compartment_id']);

        // An impossible compartment is refused as a whole: nothing is written.
        $bad = ['op' => 'modify', 'fields' => ['compartment_id' => 'no_such_compartment']];
        $badPlan = $this->service()->preview($request->id, $first, $bad, InventoryChangePlanner::SCOPE_UNIT);
        $this->assertSame('destination_missing', $this->verdictFor($badPlan, 'E1'));
        try {
            $this->service()->approve($request->id, $first, $bad, 'unit', [$this->units['E1']->id], $badPlan['fingerprint'], 2, (string) Str::uuid());
            $this->fail('A compartment that is not on this apparatus must be refused.');
        } catch (ValidationException) {
            // refused
        }
        $this->assertSame(1, ApparatusInventoryChange::query()->count());

        // Repeat clicks with one key apply once.
        $key = (string) Str::uuid();
        $one = $this->service()->approve($request->id, $first, $operation, 'unit', [$this->units['E1']->id], $plan['fingerprint'], 2, $key);
        $two = $this->service()->approve($request->id, $first, $operation, 'unit', [$this->units['E1']->id], $plan['fingerprint'], 2, $key);
        $this->assertSame($one->id, $two->id);
        $this->assertSame(2, ApparatusInventoryChangeSet::query()->count());
        $this->assertSame(2, ApparatusInventoryChange::query()->count());
        $this->assertSame(1, ApparatusInventoryCorrectionEvent::query()->where('apparatus_inventory_correction_id', $request->id)->where('event_type', 'applied')->count());
        $this->assertSame('rear_cab', $this->item('E1', 'Passports')['compartment_id']);
    }

    public function test_approval_changes_only_the_reported_apparatus_and_the_next_checkout_shows_it(): void
    {
        $reviewer = $this->logistics();
        $before = collect(array_keys($this->units))->mapWithKeys(fn (string $designation): array => [$designation => $this->resolved($designation)['checklist_version']]);
        $correction = $this->reported('E1', $this->passportsMove('E1', 'rear_cab'));

        $result = $this->approve($correction, $reviewer, ['op' => 'modify', 'fields' => ['compartment_id' => 'rear_cab']]);

        $this->assertSame('applied', $correction->fresh()->status);
        $this->assertSame([(int) $this->units['E1']->id], ApparatusInventoryChange::query()->pluck('apparatus_id')->map(fn ($id): int => (int) $id)->all(), 'one change, on the reported apparatus');
        $this->assertCount(1, ApparatusInventoryChangeSet::query()->get());
        $this->assertNotSame($before['E1'], $this->resolved('E1')['checklist_version']);
        foreach (['E3', 'E2', 'E21', 'L1', 'L3', 'R1'] as $other) {
            $this->assertSame($before[$other], $this->resolved($other)['checklist_version'], "{$other} is untouched");
        }

        // The next checkout is issued the approved list; a sibling engine still has the old one.
        $e1 = $this->getJson("/api/public/apparatuses/{$this->units['E1']->id}/checklist")->assertOk();
        $this->assertSame($this->resolved('E1')['checklist_version'], $e1->json('checklist_version'));
        $this->assertSame('rear_cab', $this->compartmentOf($e1->json('checklist'), 'Passports'));
        $e3 = $this->getJson("/api/public/apparatuses/{$this->units['E3']->id}/checklist")->assertOk();
        $this->assertSame('front_cab', $this->compartmentOf($e3->json('checklist'), 'Passports'));
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", $this->inspectionPayload('E1'))->assertCreated();

        // The member hears the result and sees it as Approved in the specific vehicle's record.
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $notification): bool => $notification->kind === Kind::INVENTORY_CORRECTION_UPDATE
            && str_contains($notification->title, 'approved'));
        $this->assertSame('Approved', app(InventoryCorrectionProjection::class)->memberDetail($correction->fresh())['status_label']);
        $this->assertSame($result['set']->id, ApparatusInventoryChangeSet::query()->sole()->id);
    }

    public function test_only_reviewers_decide_and_a_member_cannot_approve_their_own_request(): void
    {
        $correction = $this->reported('E1', $this->passportsMove('E1', 'rear_cab'));
        $operation = ['op' => 'modify', 'fields' => ['compartment_id' => 'rear_cab']];
        $adminWithoutFleet = User::factory()->create(['account_status' => AccountStatus::Active]);
        $adminWithoutFleet->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));

        foreach ([$this->member, $adminWithoutFleet->fresh()] as $outsider) {
            foreach ([
                fn () => $this->service()->approve($correction->id, $outsider, $operation, 'unit', [$this->units['E1']->id], 'x', 0, (string) Str::uuid()),
                fn () => $this->service()->reject($correction->id, $outsider, null),
                fn () => $this->service()->preview($correction->id, $outsider, $operation, 'unit'),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Only a reviewer may decide an equipment change request.');
                } catch (AuthorizationException) {
                    // refused
                }
            }
        }
        $this->assertSame('submitted', $correction->fresh()->status);
        $this->assertSame(0, ApparatusInventoryChange::query()->count());

        // The card is not available to them either, and a demoted reviewer loses it at the next poll.
        $this->asReviewer($this->member);
        $this->assertFalse(EquipmentChangeRequestsWidget::canView());
        $this->assertSame(403, $this->statusOfPolling(), 'a non-reviewer who reaches the component directly is refused');
        $this->asReviewer($adminWithoutFleet->fresh());
        $this->assertFalse(EquipmentChangeRequestsWidget::canView());
    }

    public function test_the_admin_home_card_counts_pending_requests_shows_the_diff_and_leaves_history_alone(): void
    {
        $reviewer = $this->logistics();
        $historical = $this->historicalRows();
        $historicalBefore = $this->rawRows(array_keys($historical['closed']));

        $this->actingAsCanonicalUser($this->member);
        $move = $this->reported('E1', $this->passportsMove('E1', 'rear_cab'));
        $passportsE3 = $this->item('E3', 'Passports');
        $quantity = $this->reported('E3', ['reason' => 'wrong_quantity', 'compartment_id' => $passportsE3['compartment_id'], 'item_id' => $passportsE3['item_id'], 'proposal' => ['quantity' => 3]]);

        $this->asReviewer($reviewer);
        $this->assertTrue(EquipmentChangeRequestsWidget::canView());
        $card = Livewire::test(EquipmentChangeRequestsWidget::class)
            ->assertSee('Equipment Change Requests')
            ->assertSee('3 requests pending')                       // two new + one pre-existing open request
            ->assertSeeHtml('data-pending-count="3"')
            ->assertSee('Passports')
            ->assertSee('Front Cab')
            ->assertSee('Rear Cab')                                  // before -> after, readable
            ->assertSee('Quantity')
            ->assertSeeHtml('href="'.InventoryCorrectionResource::getUrl('view', ['record' => $move->getKey()]).'"')
            ->assertSeeHtml('href="'.InventoryCorrectionResource::getUrl('view', ['record' => $quantity->getKey()]).'"')
            ->assertSeeHtml('href="'.InventoryCorrectionResource::getUrl('view', ['record' => $historical['open']->getKey()]).'"')
            ->assertDontSee('Compartment front_cab') // the pre-existing report has no stored destination name: it is resolved from the checklist, never shown as a raw id
            ->assertDontSee($historical['closed'][array_key_first($historical['closed'])]->reference());
        $this->assertSame([['label' => 'Quantity', 'before' => '2', 'after' => '3']], InventoryCorrectionDiff::rows($quantity));

        // Deciding removes a request from the card at once and the count follows.
        $this->service()->reject($quantity->id, $reviewer, null, null, 0, (string) Str::uuid());
        $card->call('poll')->assertSee('2 requests pending')->assertDontSee('Quantity:');
        foreach ([$move, $historical['open']] as $remaining) {
            $this->service()->reject($remaining->id, $reviewer, 'Not needed.', null, null, (string) Str::uuid());
        }
        $card->call('poll')->assertSee('All caught up')->assertSee('No equipment change requests are waiting');

        // Pre-existing closed requests are exactly as they were.
        $this->assertSame($historicalBefore, $this->rawRows(array_keys($historical['closed'])));
        $this->assertSame(['applied', 'rejected', 'reversed'], collect($historical['closed'])->map(fn (ApparatusInventoryCorrection $row): string => $row->fresh()->status)->values()->all());

        // The card is on the admin home page and the panel, and never on the wall display.
        $this->assertContains(EquipmentChangeRequestsWidget::class, Filament::getPanel('admin')->getWidgets());
        $this->assertContains(EquipmentChangeRequestsWidget::class, app(\App\Filament\Pages\LogisticsBoard::class)->getWidgets());
        request()->merge(['display' => 1]);
        $this->assertNotContains(EquipmentChangeRequestsWidget::class, app(Dashboard::class)->getWidgets());
        $this->assertFalse(EquipmentChangeRequestsWidget::canView());
    }

    // ------------------------------------------------------------------ helpers

    /** @param  array<string, mixed>  $checklist */
    private function compartmentOf(array $checklist, string $itemName): ?string
    {
        foreach ($checklist['compartments'] as $compartment) {
            foreach ($compartment['items'] as $item) {
                if (($item['name'] ?? null) === $itemName) {
                    return $compartment['id'];
                }
            }
        }

        return null;
    }

    private function statusOfPolling(): ?int
    {
        try {
            (new EquipmentChangeRequestsWidget)->poll();
        } catch (Throwable $exception) {
            return $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : null;
        }

        return 200;
    }

    /**
     * Requests recorded before this feature: decided ones in every final state and one still open whose
     * snapshot has no stored destination name.
     *
     * @return array{closed: array<int, ApparatusInventoryCorrection>, open: ApparatusInventoryCorrection}
     */
    private function historicalRows(): array
    {
        $passports = $this->item('L1', 'Passports');
        $make = function (string $status, array $extra = []) use ($passports): ApparatusInventoryCorrection {
            $row = new ApparatusInventoryCorrection([
                'client_request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
                'apparatus_id' => $this->units['L1']->id, 'reporter_user_id' => $this->member->id,
                'reason' => 'moved', 'compartment_key' => $passports['compartment_id'], 'item_key' => $passports['item_id'],
                'equipment_key' => 'name:passports', 'checklist_type' => 'ladder1', 'checklist_version' => str_repeat('b', 64),
                'target_snapshot' => ['compartment_id' => $passports['compartment_id'], 'compartment' => $passports['compartment'], 'name' => 'Passports'],
                'proposal' => ['to_compartment_id' => $passports['compartment_id']], 'status' => $status, ...$extra,
            ]);
            $row->created_at = now()->subDays(40);
            $row->updated_at = now()->subDays(39);
            $row->save();

            return $row;
        };

        $closed = [];
        foreach (['applied', 'rejected', 'reversed'] as $status) {
            $row = $make($status, ['decision_reason' => "history {$status}", 'decided_at' => now()->subDays(39), 'lock_version' => 3]);
            $closed[$row->getKey()] = $row;
        }

        return ['closed' => $closed, 'open' => $make('submitted')];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function rawRows(array $ids): array
    {
        return DB::table('apparatus_inventory_corrections')->whereIn('id', $ids)->orderBy('id')->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (array) $row])->all();
    }
}
