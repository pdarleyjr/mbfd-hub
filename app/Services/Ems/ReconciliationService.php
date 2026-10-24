<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Reconcile supplies issued since last inventory" (docs/ems/ems-inventory-contract.md section 6).
 *
 * preview(): per item, the previous value, issued, received, adjustments, the calculated quantity,
 * the threshold and warnings (no previous count, unknown conversion, unmatched issuances, negative
 * result). Nothing is saved.
 * confirm(): writes ONE final `reconciliation` snapshot with value_source = calculated. Its
 * idempotency key is hash(previous snapshot id, last movement id included), and when nothing was
 * recorded since the newest snapshot there is nothing to reconcile and that snapshot is returned,
 * so confirming twice never creates a second snapshot. A movement belongs to exactly one snapshot
 * (by movement id), so consecutive reconciliations never deduct the same issuance twice.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly Idempotency $idempotency,
        private readonly EmsAccess $access,
        private readonly SnapshotPresenter $presenter,
    ) {}

    /** @return array<string, mixed> */
    public function preview(): array
    {
        $items = SupplyItem::query()->with('location:id,code')->where('active', true)->where('controlled_substance', false)->orderBy('name')->get();
        $baselines = $this->ledger->baselines($items->pluck('id')->all());
        $throughByItem = $items->mapWithKeys(fn (SupplyItem $item): array => [$item->getKey() => $baselines[$item->getKey()]['through'] ?? 0])->all();
        $sums = $this->ledger->movementSums($throughByItem);
        $unmatched = $this->unmatchedIssuances();
        $unmatchedByCatalog = collect($unmatched)->groupBy('catalog_item_id');

        $rows = [];
        $negative = 0;
        $without = 0;
        foreach ($items as $item) {
            $baseline = $baselines[$item->getKey()] ?? null;
            $sum = $sums[$item->getKey()] ?? ['issued' => 0, 'received' => 0, 'adjusted' => 0, 'count' => 0, 'last_id' => 0];
            $calculated = $baseline !== null ? $baseline['quantity'] + $sum['issued'] + $sum['received'] + $sum['adjusted'] : null;

            $warnings = [];
            if ($baseline === null) {
                $warnings[] = 'no_previous_count';
                $without++;
            }
            if ($item->threshold_qty_base === null && $item->threshold_source_text !== null) {
                $warnings[] = 'unknown_conversion';
            }
            if ($item->catalog_item_id !== null && $unmatchedByCatalog->has($item->catalog_item_id)) {
                $warnings[] = 'unmatched_issuances';
            }
            if ($calculated !== null && $calculated < 0) {
                $warnings[] = 'negative_result';
                $negative++;
            }

            $rows[] = [
                'supply_item_id' => $item->getKey(),
                'name' => $item->name,
                'location_code' => $item->location?->code,
                'previous_quantity' => $baseline['quantity'] ?? null,
                'previous_basis_label' => $baseline === null ? 'Not counted yet' : ($baseline['count_at']?->setTimezone(StockLedger::TIMEZONE)->format('M j, Y') ?? null),
                'issued' => abs($sum['issued']),
                'received' => $sum['received'],
                'adjusted' => $sum['adjusted'],
                'movement_count' => $sum['count'],
                'calculated_quantity' => $calculated,
                'threshold_qty_base' => $item->threshold_qty_base,
                'threshold_source_text' => $item->threshold_source_text,
                'below_threshold' => $calculated !== null && $item->threshold_qty_base !== null ? $calculated < $item->threshold_qty_base : null,
                'warnings' => $warnings,
            ];
        }

        $latest = $this->latestFinal();
        $lastMovement = $this->ledger->maxMovementId();
        // Nothing to reconcile when no item that has a previous number has had a movement since it.
        $nothingNew = $latest !== null && ! collect($rows)->contains(fn (array $row): bool => $row['previous_quantity'] !== null && $row['movement_count'] > 0);

        return [
            'previous_snapshot' => $latest !== null ? $this->presenter->summary($latest) : null,
            'last_movement_id' => $lastMovement,
            'idempotency_key' => $this->hashKey($latest?->getKey(), $lastMovement),
            'nothing_to_reconcile' => $nothingNew || $items->isEmpty(),
            'summary' => [
                'items' => count($rows),
                'without_previous_count' => $without,
                'negative_results' => $negative,
                'movements_included' => (int) collect($sums)->sum('count'),
            ],
            'unmatched_issuances' => $unmatched,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function confirm(User $user, string $key, mixed $expectedLastMovementId): array
    {
        if (! $this->access->canAct($user)) {
            throw EmsException::forbidden();
        }
        $expected = EmsValidator::validate(['expected_last_movement_id' => $expectedLastMovementId], [
            'expected_last_movement_id' => ['required', 'integer', 'min:0'],
        ], ['expected_last_movement_id.required' => 'Open the reconciliation preview first, then confirm what it showed.', 'expected_last_movement_id.integer' => 'Open the reconciliation preview first, then confirm what it showed.'])['expected_last_movement_id'];

        return $this->idempotency->run($user, 'ems.reconcile', $key, function () use ($user, $expected): array {
            $this->ledger->lock();
            $latest = $this->latestFinal();
            $lastMovement = $this->ledger->maxMovementId();

            // Confirm exactly what was previewed: anything recorded since then has not been reviewed.
            if ((int) $expected !== $lastMovement) {
                throw EmsException::conflict('New stock changes were recorded since you opened this preview. Review the updated numbers.', 'stale_preview');
            }

            $preview = $this->preview();
            if ($latest !== null && $preview['nothing_to_reconcile']) {
                return $this->presenter->detail($latest) + ['unchanged' => true];
            }
            $idempotencyKey = $this->hashKey($latest?->getKey(), $lastMovement);
            $existing = InventorySnapshot::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->presenter->detail($existing) + ['unchanged' => true];
            }

            if ($preview['summary']['items'] === 0) {
                throw new EmsException('There are no supply room items to reconcile yet.', 'nothing_to_reconcile', 422);
            }
            $baselines = $this->ledger->baselines();
            $actor = EmsActor::forUser($user, 'captain5');

            $snapshot = InventorySnapshot::query()->create([
                'uuid' => (string) Str::uuid(), 'kind' => InventorySnapshot::KIND_RECONCILIATION, 'status' => InventorySnapshot::DRAFT,
                'started_by_user_id' => $user->getKey(), 'started_at' => now(), 'idempotency_key' => $idempotencyKey,
                'previous_snapshot_id' => $latest?->getKey(),
                'notes' => 'Calculated from the last counts and the stock movements recorded since. Not a physical count.',
            ]);
            foreach ($preview['rows'] as $row) {
                $baseline = $baselines[$row['supply_item_id']] ?? null;
                InventorySnapshotLine::query()->create([
                    'snapshot_id' => $snapshot->getKey(), 'supply_item_id' => $row['supply_item_id'], 'location_code' => $row['location_code'],
                    'item_name' => $row['name'], 'quantity_base' => $row['calculated_quantity'],
                    'value_source' => $row['calculated_quantity'] === null ? InventorySnapshotLine::NOT_COUNTED : InventorySnapshotLine::CALCULATED,
                    'entry' => $baseline === null ? null : [
                        'previous_quantity' => $row['previous_quantity'], 'issued' => $row['issued'], 'received' => $row['received'], 'adjusted' => $row['adjusted'],
                        'count_at' => $baseline['count_at']?->toIso8601String(), 'count_snapshot_id' => $baseline['count_snapshot_id'],
                    ],
                    'warnings' => $row['warnings'] === [] ? null : $row['warnings'],
                ]);
            }
            $snapshot->update([
                'status' => InventorySnapshot::FINAL, 'version' => (int) InventorySnapshot::query()->max('version') + 1,
                'finalized_by_user_id' => $user->getKey(), 'finalized_name' => $actor->name, 'finalized_at' => now(), 'cutoff_at' => now(),
                'through_movement_id' => $lastMovement,
            ]);

            return $this->presenter->detail($snapshot->fresh()) + ['unchanged' => false];
        });
    }

    private function latestFinal(): ?InventorySnapshot
    {
        return InventorySnapshot::query()->where('status', InventorySnapshot::FINAL)->orderByDesc('version')->first();
    }

    private function hashKey(?int $previousId, int $lastMovementId): string
    {
        return 'recon:'.hash('sha256', ($previousId ?? 0).'|'.$lastMovementId);
    }

    /**
     * Issuances that could not change stock and were not fixed by a later correction.
     *
     * @return list<array<string, mixed>>
     */
    public function unmatchedIssuances(): array
    {
        // The current state of each issuance: the original event, or its newest correction.
        $latestCorrection = InventoryRequestEvent::query()->where('action', 'issue_corrected')->orderBy('id')->get()
            ->mapWithKeys(fn (InventoryRequestEvent $e): array => [(int) ($e->payload['corrects_event_id'] ?? 0) => $e->payload]);

        $events = InventoryRequestEvent::query()->where('action', 'issued')->whereNotNull('request_line_id')->orderBy('id')->get()
            ->map(function (InventoryRequestEvent $e) use ($latestCorrection): InventoryRequestEvent {
                $current = $latestCorrection->get((int) $e->getKey());
                // Carry the current effect, quantity and note on the event object (never saved).
                $e->setAttribute('current_effect', $current['stock_effect'] ?? ($e->payload['stock_effect'] ?? null));
                $e->setAttribute('current_quantity', (int) ($current['new_quantity'] ?? ($e->payload['quantity'] ?? 0)));
                $e->setAttribute('current_note', $current !== null ? ($current['stock_note'] ?? $current['reason'] ?? null) : ($e->payload['stock_note'] ?? null));

                return $e;
            })
            ->filter(fn (InventoryRequestEvent $e): bool => $e->getAttribute('current_effect') === RequestWorkflowService::EFFECT_UNMATCHED && $e->getAttribute('current_quantity') > 0)
            ->values();
        if ($events->isEmpty()) {
            return [];
        }
        $requests = InventoryRequest::query()->whereIn('id', $events->pluck('request_id')->unique()->all())->get()->keyBy('id');
        $lines = InventoryRequestLine::query()->whereIn('id', $events->pluck('request_line_id')->unique()->all())->get()->keyBy('id');

        return $events->map(fn (InventoryRequestEvent $e): array => [
            'event_id' => (int) $e->getKey(),
            'request_id' => $requests->get($e->request_id)?->uuid,
            'request_number' => $requests->get($e->request_id)?->number,
            'label' => $lines->get($e->request_line_id)?->label,
            'catalog_item_id' => $lines->get($e->request_line_id)?->catalog_item_id,
            'quantity' => $e->getAttribute('current_quantity'),
            'note' => $e->getAttribute('current_note'),
            'issued_at' => $e->created_at?->toIso8601String(),
        ])->all();
    }
}
