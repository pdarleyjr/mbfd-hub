<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Monday count form (docs/ems/captain5-medical-inventory-workflow.md section 5): one room-wide draft
 * any Captain 5 can resume, one typed number per item, then one submit. It sits on InventoryCountService,
 * so a form count and an Admin count are the same snapshot.
 */
final class CountFormService
{
    private const ENTRY_MAX = 999999;

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly StockLedger $ledger,
        private readonly EmsAccess $access,
        private readonly InventoryCountService $counts,
    ) {}

    /**
     * Start a count, or resume the one in progress. The stored result is small on purpose: the items are read fresh.
     *
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function start(User $user, string $key): array
    {
        $this->authorize($user);

        return $this->idempotency->run($user, 'ems.countform.start', $key, function () use ($user): array {
            $this->ledger->lock();
            $draft = $this->openDraft();
            $resumed = $draft !== null;
            $draft ??= InventorySnapshot::query()->create([
                'uuid' => (string) Str::uuid(), 'kind' => InventorySnapshot::KIND_COUNT, 'origin' => InventorySnapshot::ORIGIN_COUNT_FORM,
                'status' => InventorySnapshot::DRAFT, 'started_by_user_id' => $user->getKey(), 'started_at' => now(),
            ]);

            return ['id' => $draft->uuid, 'resumed' => $resumed];
        });
    }

    public function openDraft(): ?InventorySnapshot
    {
        return InventorySnapshot::query()->where('kind', InventorySnapshot::KIND_COUNT)->where('status', InventorySnapshot::DRAFT)
            ->whereNull('scope_location_code')->orderBy('id')->first();
    }

    public function draftByUuid(string $uuid): InventorySnapshot
    {
        $snapshot = InventorySnapshot::query()->where('uuid', $uuid)->first();
        if ($snapshot === null || $snapshot->kind !== InventorySnapshot::KIND_COUNT) {
            throw EmsException::notFound('count');
        }

        return $snapshot;
    }

    /**
     * What has been typed so far, in each item's count unit. A null number never appears here.
     *
     * @return array<int, int> supply_item_id => typed number
     */
    public function entries(InventorySnapshot $snapshot): array
    {
        $lines = $snapshot->lines()->whereNotNull('quantity_base')->get();
        $items = SupplyItem::query()->whereIn('id', $lines->pluck('supply_item_id')->all())->get()->keyBy('id');
        $entries = [];
        foreach ($lines as $line) {
            $item = $items->get($line->supply_item_id);
            if ($item !== null) {
                $entries[(int) $line->supply_item_id] = (int) floor((int) $line->quantity_base / CountMath::perCountUnit($item));
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $input  {entries: [{supply_item_id, on_hand: int|null}]}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function save(string $uuid, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, [
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.supply_item_id' => ['required', 'integer'],
            'entries.*.on_hand' => ['present', 'nullable', 'integer', 'min:0', 'max:'.self::ENTRY_MAX],
        ], [
            'entries.required' => 'There is nothing to save.',
            'entries.*.on_hand.integer' => 'Counts must be whole numbers.',
            'entries.*.on_hand.min' => 'Counts cannot be negative.',
            'entries.*.on_hand.max' => 'That count is too large. Check the number.',
        ]);
        $id = (int) $this->draftByUuid($uuid)->getKey();

        return $this->idempotency->run($user, "ems.countform.save.{$id}", $key, function () use ($id, $data): array {
            $this->ledger->lock();
            $through = $this->ledger->maxMovementId();
            $snapshot = $this->counts->lockDraft($id);
            $items = SupplyItem::query()->whereIn('id', collect($data['entries'])->pluck('supply_item_id')->all())->get()->keyBy('id');
            $lines = [];
            foreach ($data['entries'] as $row) {
                $item = $items->get((int) $row['supply_item_id']);
                if ($item === null) {
                    throw new EmsException('One of those items is not in the supply room.', 'item_not_counted', 422);
                }
                if ($row['on_hand'] !== null) {
                    CountMath::assertConvertible($item);
                }
                $lines[] = ['supply_item_id' => (int) $row['supply_item_id']] + ($row['on_hand'] === null ? [] : CountMath::entryFor($item, (int) $row['on_hand']));
            }
            $this->counts->writeLines($snapshot, $lines, $through);

            return ['saved' => count($lines), 'entries' => (object) $this->entries($snapshot)];
        });
    }

    /** @return array<string, mixed> */
    public function review(string $uuid): array
    {
        $snapshot = $this->draftByUuid($uuid);
        if ($snapshot->isFinal()) {
            throw EmsException::conflict('This count was already submitted.', 'count_final');
        }
        $lines = $snapshot->lines()->whereNotNull('quantity_base')->get()->keyBy('supply_item_id');
        $uncounted = [];
        $orders = [];
        $problems = [];
        foreach ($this->inScope() as $item) {
            /** @var InventorySnapshotLine|null $line */
            $line = $lines->get($item->getKey());
            if ($line === null) {
                $uncounted[] = ['id' => $item->getKey(), 'name' => $item->name, 'location_code' => $item->location?->code];

                continue;
            }
            $problem = CountMath::configProblem($item);
            if ($problem !== null) {
                $problems[] = ['id' => $item->getKey(), 'name' => $item->name, 'problem' => $problem];
            }
            $per = CountMath::perCountUnit($item);
            $order = CountMath::orderInCountUnits(CountMath::orderBase($item->threshold_qty_base, (int) $line->quantity_base), $per);
            if ((int) $order > 0) {
                $orders[] = [
                    'supply_item_id' => $item->getKey(), 'name' => $item->name, 'location_code' => $item->location?->code,
                    'on_hand' => CountMath::display((int) $line->quantity_base, $per), 'order' => $order, 'unit_label' => CountMath::label($item->count_unit, $order),
                ];
            }
        }

        return ['counted' => $lines->count(), 'uncounted' => $uncounted, 'orders' => $orders, 'config_problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $input  {expected_uncounted}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function submit(string $uuid, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, ['expected_uncounted' => ['required', 'integer', 'min:0']], [
            'expected_uncounted.required' => 'Review the missing items before you submit.',
        ]);
        $id = (int) $this->draftByUuid($uuid)->getKey();

        return $this->idempotency->run($user, "ems.countform.submit.{$id}", $key, function () use ($id, $user, $data): array {
            $this->ledger->lock();
            $snapshot = $this->counts->lockDraft($id);
            $counted = $snapshot->lines()->whereNotNull('quantity_base')->pluck('supply_item_id')->map(fn ($itemId): int => (int) $itemId);
            $missing = $this->inScope()->reject(fn (SupplyItem $item): bool => $counted->contains((int) $item->getKey()))->count();
            if ($missing !== (int) $data['expected_uncounted']) {
                throw EmsException::conflict('The list of items not yet counted changed. Check the review and submit again.', 'uncounted_changed');
            }
            $this->counts->close($snapshot, $user);
            $snapshot->refresh();

            return [
                'id' => $snapshot->uuid, 'finalized_at' => $snapshot->finalized_at?->toIso8601String(), 'finalized_name' => $snapshot->finalized_name,
                'counted_items' => $snapshot->counted_items, 'uncounted_items' => $snapshot->uncounted_items, 'order_items' => $snapshot->order_items,
            ];
        });
    }

    /** @return Collection<int, SupplyItem> */
    private function inScope(): Collection
    {
        return SupplyItem::query()->with('location:id,code,sort')->where('active', true)->where('controlled_substance', false)->orderBy('id')->get();
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canAct($user)) {
            throw EmsException::forbidden();
        }
    }
}
