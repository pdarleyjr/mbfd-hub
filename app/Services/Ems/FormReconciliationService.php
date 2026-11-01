<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\CatalogSupplyLink;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Friday: selected, acknowledged forms come off the supply room shelf together
 * (docs/ems/captain5-medical-inventory-workflow.md section 3).
 *
 * All or nothing: if one form or line cannot be applied, no stock moves and no form is archived. Request rows
 * lock before the stock lock, the same order the older issue path uses. A form that already had anything
 * issued is never eligible, so one request only ever has one deduction path.
 */
final class FormReconciliationService
{
    public const MAX_FORMS = 50;

    private const READY = 'ready';

    private const NEEDS_ITEM = 'needs_item';

    private const NEEDS_UNIT = 'needs_unit';

    private const NO_STOCK_EFFECT = 'no_stock_effect';

    private const NOT_STOCKED = 'not_stocked';

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly StockLedger $ledger,
        private readonly EmsAccess $access,
        private readonly RequestWorkflowService $workflow,
    ) {}

    /**
     * What confirming would do. Reads only.
     *
     * @param  list<string>  $uuids
     * @return array<string, mixed>
     */
    public function preview(array $uuids): array
    {
        $requests = $this->requests($uuids, lock: false);
        $forms = [];
        $lines = [];
        $plans = [];

        foreach ($requests as $request) {
            $blocker = $this->blocker($request);
            $forms[] = [
                'id' => $request->uuid, 'number' => $request->number, 'target_label' => $request->target_label,
                'submitter_name' => $request->submitter_name, 'submitted_at' => $request->submitted_at->toIso8601String(),
                'eligible' => $blocker === null, 'ineligible_reason' => $blocker?->getMessage(),
            ];
            if ($blocker !== null) {
                continue;
            }
            foreach ($request->lines as $line) {
                if ($this->nothingToTake($line)) {
                    continue;
                }
                $plan = $this->plan($line, null);
                $plans[] = $plan;
                $lines[] = [
                    'line_id' => $line->getKey(), 'request_id' => $request->uuid, 'number' => $request->number, 'target_label' => $request->target_label,
                    'label' => $line->label, 'variant_label' => $line->variant_label, 'requested_qty' => $line->requested_qty, 'unit' => $line->unit,
                    'quantity' => $plan['quantity'], 'quantity_base' => $plan['quantity_base'],
                    'state' => $plan['state'], 'message' => $plan['note'],
                    'item' => $plan['item'] === null ? null : [
                        'id' => $plan['item']->getKey(), 'name' => $plan['item']->name,
                        'location_code' => $plan['item']->location?->code, 'count_unit' => $plan['item']->count_unit,
                    ],
                ];
            }
        }

        return [
            'last_movement_id' => $this->ledger->maxMovementId(),
            'blocked' => count(array_filter($lines, fn (array $line): bool => in_array($line['state'], [self::NEEDS_ITEM, self::NEEDS_UNIT], true))),
            'forms' => $forms,
            'lines' => $lines,
            'totals' => $this->totals($plans),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  {request_ids, expected_last_movement_id, accept_low_stock?, decisions?}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function reconcile(User $user, string $key, array $input): array
    {
        if (! $this->access->canAct($user)) {
            throw EmsException::forbidden();
        }
        $data = EmsValidator::validate($input, [
            'request_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_FORMS],
            'request_ids.*' => ['required', 'uuid', 'distinct'],
            'expected_last_movement_id' => ['required', 'integer', 'min:0'],
            'accept_low_stock' => ['sometimes', 'boolean'],
            'decisions' => ['sometimes', 'array', 'max:2000'],
            'decisions.*.line_id' => ['required', 'integer'],
            'decisions.*.quantity' => ['nullable', 'integer', 'min:0', 'max:'.RequestFormDefinition::MAX_QUANTITY],
            'decisions.*.supply_item_id' => ['nullable', 'integer'],
            'decisions.*.quantity_base' => ['nullable', 'integer', 'min:0', 'max:'.StockLedger::MAX_BASE],
            'decisions.*.not_stocked' => ['nullable', 'boolean'],
        ], [
            'request_ids.required' => 'Select at least one form to reconcile.',
            'request_ids.max' => 'Reconcile at most '.self::MAX_FORMS.' forms at a time.',
            'decisions.*.quantity.integer' => 'Quantities must be whole numbers.',
            'decisions.*.quantity.max' => 'A quantity can be at most '.RequestFormDefinition::MAX_QUANTITY.'.',
        ]);

        return $this->idempotency->run($user, 'ems.forms.reconcile', $key, function () use ($user, $data): array {
            $requests = $this->requests(array_values($data['request_ids']), lock: true);
            $this->ledger->lock();

            // State first: a second person finds out the form is done, not that "stock changed".
            foreach ($requests as $request) {
                if (($blocker = $this->blocker($request)) !== null) {
                    throw $blocker;
                }
            }
            if ((int) $data['expected_last_movement_id'] !== $this->ledger->maxMovementId()) {
                throw EmsException::conflict('Stock changed since you opened this. Check the numbers and confirm again.', 'stale_preview');
            }

            $decisions = collect($data['decisions'] ?? [])->keyBy(fn (array $decision): int => (int) $decision['line_id']);
            $this->assertChosenItemsCanTakeStock($decisions);
            [$plans, $blocked] = $this->planAll($requests, $decisions);
            if ($blocked !== []) {
                throw new EmsException('Some lines still need an item or a number before these forms can be reconciled.', 'needs_correction', 422, ['lines' => array_map('strval', $blocked)]);
            }
            $this->assertStockAccepted($plans, (bool) ($data['accept_low_stock'] ?? false));

            $actor = EmsActor::staff($user, $this->access);
            $batch = (string) Str::uuid();
            $movementCount = 0;
            foreach ($requests as $request) {
                foreach ($request->lines as $line) {
                    $plan = $plans[(int) $line->getKey()] ?? null;
                    if ($plan !== null && $this->workflow->applyReconciledLine($request, $actor, $line, $plan, $batch) !== null) {
                        $movementCount++;
                    }
                }
                $this->workflow->markReconciled($request, $actor, $batch, $requests->count());
            }
            $this->remember($requests, $decisions->only(array_keys($plans)), $actor);

            return [
                'batch' => $batch,
                'reconciled' => $requests->map(fn (InventoryRequest $request): array => ['id' => $request->uuid, 'number' => $request->number])->values()->all(),
                'movement_count' => $movementCount,
                'deducted' => array_map(
                    fn (array $total): array => ['supply_item_id' => $total['supply_item_id'], 'name' => $total['name'], 'deduct_base' => $total['deduct_base']],
                    $this->totals(array_values($plans)),
                ),
            ];
        });
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, InventoryRequest>
     */
    private function requests(array $uuids, bool $lock): Collection
    {
        if ($uuids === [] || count($uuids) > self::MAX_FORMS) {
            throw new EmsException('Select between 1 and '.self::MAX_FORMS.' forms to reconcile.', 'invalid_selection', 422);
        }
        $requests = InventoryRequest::query()->whereIn('uuid', $uuids)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($requests->count() !== count(array_unique($uuids))) {
            throw EmsException::notFound('form');
        }

        return $requests->each(fn (InventoryRequest $request) => $request->load(['lines.catalogItem']));
    }

    private function blocker(InventoryRequest $request): ?EmsException
    {
        if ($request->status === InventoryRequest::RECONCILED) {
            return EmsException::conflict("{$request->number} was already reconciled.", 'already_reconciled');
        }
        if (! in_array($request->status, InventoryRequest::WORKABLE_STATUSES, true)) {
            return EmsException::conflict("{$request->number} can no longer be reconciled. It may have been sent back, replaced, cancelled or closed.", 'not_eligible');
        }
        if ($request->hasIssuance()) {
            return EmsException::conflict("{$request->number} had supplies issued under the older workflow, so it is never deducted again.", 'not_eligible');
        }
        if (! $request->isAcknowledged()) {
            return EmsException::conflict("{$request->number} has to be acknowledged before it can be reconciled.", 'not_eligible');
        }

        return null;
    }

    /**
     * Checked up front because a line can return before it reaches resolveStock (a quantity of 0), and the choice is remembered.
     *
     * @param  Collection<int, array<string, mixed>>  $decisions
     */
    private function assertChosenItemsCanTakeStock(Collection $decisions): void
    {
        $chosen = $decisions->pluck('supply_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $usable = SupplyItem::query()->whereIn('id', $chosen->all())->where('active', true)->where('controlled_substance', false)->count();
        if ($usable !== $chosen->count()) {
            throw new EmsException('One of the chosen items is not a supply room item that can be deducted. Choose another.', 'unknown_supply_item', 422);
        }
    }

    /** A "No" answer has nothing to take and needs no decision. */
    private function nothingToTake(InventoryRequestLine $line): bool
    {
        return $line->line_status === InventoryRequestLine::NOT_ISSUED || $line->wantedQty() === 0;
    }

    /**
     * @param  Collection<int, InventoryRequest>  $requests
     * @param  Collection<int, array<string, mixed>>  $decisions
     * @return array{0: array<int, array<string, mixed>>, 1: list<int>}
     */
    private function planAll(Collection $requests, Collection $decisions): array
    {
        $plans = [];
        $blocked = [];
        foreach ($requests as $request) {
            foreach ($request->lines as $line) {
                if ($this->nothingToTake($line)) {
                    continue;
                }
                $plan = $this->plan($line, $decisions->get((int) $line->getKey()));
                if (in_array($plan['state'], [self::NEEDS_ITEM, self::NEEDS_UNIT], true)) {
                    $blocked[] = (int) $line->getKey();
                }
                $plans[(int) $line->getKey()] = $plan;
            }
        }

        return [$plans, $blocked];
    }

    /**
     * Decide what one line does, in the contract's order: the person's choice, the remembered link, the single
     * catalog match. Names are never compared and a unit is never guessed.
     *
     * @param  array<string, mixed>|null  $decision
     * @return array{state: string, quantity: int, effect: string, item: SupplyItem|null, quantity_base: int|null, note: string|null}
     */
    private function plan(InventoryRequestLine $line, ?array $decision): array
    {
        $quantity = isset($decision['quantity']) ? (int) $decision['quantity'] : $line->wantedQty();

        if ($quantity === 0) {
            return ['state' => self::READY, 'quantity' => 0, 'effect' => RequestWorkflowService::EFFECT_NONE, 'item' => null, 'quantity_base' => 0, 'note' => 'Not taken'];
        }
        if ((bool) ($decision['not_stocked'] ?? false)) {
            return ['state' => self::NOT_STOCKED, 'quantity' => $quantity, 'effect' => RequestWorkflowService::EFFECT_NOT_STOCKED, 'item' => null, 'quantity_base' => null, 'note' => 'Not a supply room item'];
        }

        $stock = $this->workflow->resolveStock($line, $quantity, isset($decision['supply_item_id']) ? (int) $decision['supply_item_id'] : null);
        $stock['item']?->loadMissing('location:id,code');

        return match (true) {
            $stock['effect'] === RequestWorkflowService::EFFECT_APPLIED => ['state' => self::READY, 'quantity' => $quantity, 'effect' => $stock['effect'], 'item' => $stock['item'], 'quantity_base' => $stock['base'], 'note' => null],
            $stock['effect'] === RequestWorkflowService::EFFECT_NOT_STOCKED => ['state' => self::NOT_STOCKED, 'quantity' => $quantity, 'effect' => $stock['effect'], 'item' => null, 'quantity_base' => null, 'note' => $stock['note']],
            $stock['effect'] === RequestWorkflowService::EFFECT_NONE => ['state' => self::NO_STOCK_EFFECT, 'quantity' => $quantity, 'effect' => $stock['effect'], 'item' => null, 'quantity_base' => null, 'note' => $stock['note']],
            $stock['item'] !== null && (int) ($decision['quantity_base'] ?? 0) > 0 => ['state' => self::READY, 'quantity' => $quantity, 'effect' => RequestWorkflowService::EFFECT_APPLIED, 'item' => $stock['item'], 'quantity_base' => (int) $decision['quantity_base'], 'note' => null],
            $stock['item'] !== null && isset($decision['quantity_base']) => ['state' => self::READY, 'quantity' => $quantity, 'effect' => RequestWorkflowService::EFFECT_NONE, 'item' => $stock['item'], 'quantity_base' => 0, 'note' => 'None came off the shelf'],
            $stock['item'] !== null => ['state' => self::NEEDS_UNIT, 'quantity' => $quantity, 'effect' => $stock['effect'], 'item' => $stock['item'], 'quantity_base' => null, 'note' => "This form counts in \"{$line->unit}\". Enter how many units come off the shelf."],
            default => ['state' => self::NEEDS_ITEM, 'quantity' => $quantity, 'effect' => $stock['effect'], 'item' => null, 'quantity_base' => null, 'note' => $stock['note']],
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    private function totals(array $plans): array
    {
        $byItem = [];
        foreach ($plans as $plan) {
            if ($plan['effect'] !== RequestWorkflowService::EFFECT_APPLIED || $plan['item'] === null || (int) $plan['quantity_base'] === 0) {
                continue;
            }
            $id = (int) $plan['item']->getKey();
            $byItem[$id] ??= ['item' => $plan['item'], 'deduct_base' => 0];
            $byItem[$id]['deduct_base'] += (int) $plan['quantity_base'];
        }
        if ($byItem === []) {
            return [];
        }
        $current = $this->ledger->current(collect(array_column($byItem, 'item')));

        return array_values(array_map(function (array $row) use ($current): array {
            /** @var SupplyItem $item */
            $item = $row['item'];
            $before = $current[$item->getKey()]['quantity'] ?? null;

            return [
                'supply_item_id' => (int) $item->getKey(), 'name' => $item->name, 'location_code' => $item->location?->code,
                'count_unit' => $item->count_unit, 'units_per_count_unit' => CountMath::perCountUnit($item),
                'deduct_base' => $row['deduct_base'], 'before_base' => $before, 'after_base' => $before === null ? null : $before - $row['deduct_base'],
                'low' => $before !== null && $before - $row['deduct_base'] < 0, 'not_counted' => $before === null,
            ];
        }, $byItem));
    }

    /** @param array<int, array<string, mixed>> $plans */
    private function assertStockAccepted(array $plans, bool $accepted): void
    {
        if ($accepted) {
            return;
        }
        $low = array_filter($this->totals(array_values($plans)), fn (array $total): bool => $total['low']);
        if ($low !== []) {
            $names = implode(', ', array_column($low, 'name'));
            throw EmsException::conflict("This would take more than the books show for: {$names}. Confirm to continue; Monday's count will correct the number.", 'low_stock');
        }
    }

    /**
     * A person's choice is kept so the same form item resolves itself next time.
     *
     * @param  Collection<int, InventoryRequest>  $requests
     * @param  Collection<int, array<string, mixed>>  $decisions
     */
    private function remember(Collection $requests, Collection $decisions, EmsActor $actor): void
    {
        $lines = $requests->flatMap(fn (InventoryRequest $request) => $request->lines)->keyBy(fn (InventoryRequestLine $line): int => (int) $line->getKey());
        foreach ($decisions as $lineId => $decision) {
            $catalogId = $lines->get($lineId)?->catalog_item_id;
            $chose = isset($decision['supply_item_id']);
            $notStocked = (bool) ($decision['not_stocked'] ?? false);
            if ($catalogId === null || (! $chose && ! $notStocked)) {
                continue;
            }
            CatalogSupplyLink::query()->updateOrCreate(['catalog_item_id' => $catalogId], [
                'supply_item_id' => $notStocked ? null : (int) $decision['supply_item_id'],
                'not_stocked' => $notStocked,
                'source' => CatalogSupplyLink::SOURCE_PERSON,
                'decided_by_user_id' => $actor->userId,
                'decided_name' => $actor->name,
            ]);
        }
    }
}
