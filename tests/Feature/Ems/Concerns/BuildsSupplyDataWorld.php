<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Concerns;

use App\Models\Apparatus;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Hand-built supply room history for the Supply Data tests: items, requests, ledger movements and counts written
 * straight to the tables (never through the workflow services), so each test states exactly the shape it checks.
 * Times are written as Eastern wall-clock strings and stored as UTC.
 */
trait BuildsSupplyDataWorld
{
    private int $sdSequence = 0;

    /** Today for every Supply Data test: Monday, November 2, 2026, noon Eastern (after the DST change on Nov 1). */
    protected const SD_NOW = '2026-11-02 12:00:00';

    protected function freezeSupplyDataClock(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::SD_NOW, 'America/New_York'));
    }

    protected function sdUtc(string $eastern): CarbonImmutable
    {
        return CarbonImmutable::parse($eastern, 'America/New_York')->utc();
    }

    /** @param array<string, mixed> $attributes */
    protected function sdItem(string $name, array $attributes = []): SupplyItem
    {
        $this->sdSequence++;

        return SupplyItem::query()->create($attributes + [
            'name' => $name, 'base_unit' => 'each', 'count_unit' => 'each', 'active' => true, 'controlled_substance' => false,
            'source_row_key' => 'sd.'.$this->sdSequence,
        ]);
    }

    protected function sdApparatus(string $name, string $unitId): Apparatus
    {
        $station = Station::query()->firstOrCreate(['station_number' => 2], ['name' => 'Station 2', 'address' => '1 Test St', 'is_active' => true]);
        $this->sdSequence++;

        return Apparatus::query()->create([
            'station_id' => $station->id, 'vin' => 'VIN-SD-'.$this->sdSequence, 'assignment' => 'Station 2', 'unit_id' => $unitId, 'designation' => $unitId,
            'name' => $name, 'type' => 'Rescue', 'vehicle_number' => 'V-'.$unitId, 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
        ]);
    }

    /**
     * A request with its lines. Lines: [label, requested_qty, issued_qty] with an optional 4th element of extra attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: int, 2?: int, 3?: array<string, mixed>}>  $lines
     */
    protected function sdRequest(?Apparatus $unit, string $status, string $submittedAt, array $lines = [], array $attributes = []): InventoryRequest
    {
        $this->sdSequence++;
        $request = InventoryRequest::query()->create($attributes + [
            'uuid' => (string) Str::uuid(), 'number' => sprintf('MIR-%06d', $this->sdSequence), 'status' => $status,
            'submitter_name' => 'Test Member', 'submitted_at' => $this->sdUtc($submittedAt),
            'target_kind' => $unit === null ? InventoryRequest::TARGET_DETAIL : InventoryRequest::TARGET_RESCUE,
            'apparatus_id' => $unit?->getKey(), 'target_label' => $unit === null ? 'Detail / Special Event' : (string) $unit->name,
            'client_submission_id' => (string) Str::uuid(), 'source_form_versions' => [], 'snapshot' => [], 'snapshot_sha256' => str_repeat('a', 64),
        ]);
        foreach ($lines as $index => $line) {
            InventoryRequestLine::query()->create(($line[3] ?? []) + [
                'request_id' => $request->getKey(), 'bag_key' => 'bag', 'item_key' => 'item-'.$this->sdSequence.'-'.$index, 'label' => $line[0],
                'response_type' => 'quantity', 'requested_qty' => $line[1], 'issued_qty' => $line[2] ?? 0, 'unit' => 'each', 'sort' => $index,
            ]);
        }

        return $request;
    }

    /** @param array<string, mixed> $extra */
    protected function sdMovement(SupplyItem $item, string $type, int $quantity, ?InventoryRequest $request, string $at, array $extra = []): StockMovement
    {
        $this->sdSequence++;

        return StockMovement::query()->create($extra + [
            'uuid' => (string) Str::uuid(), 'supply_item_id' => $item->getKey(), 'movement_type' => $type, 'quantity_base' => $quantity,
            'occurred_at' => $this->sdUtc($at), 'request_id' => $request?->getKey(), 'idempotency_key' => 'sd-'.$this->sdSequence.'-'.Str::random(6),
            'actor_name' => 'Test', 'reason' => $extra['reason'] ?? null,
        ]);
    }

    /** An issuance of $quantity units for a request (a negative ledger row). */
    protected function sdIssue(SupplyItem $item, int $quantity, InventoryRequest $request, string $at): StockMovement
    {
        return $this->sdMovement($item, StockMovement::ISSUED, -$quantity, $request, $at);
    }

    /** The compensating row a correction writes: it adds back what an issuance took and points at it. */
    protected function sdReverse(StockMovement $issued, InventoryRequest $request, string $at, ?int $quantity = null): StockMovement
    {
        return $this->sdMovement($issued->supplyItem, StockMovement::ADJUSTED, $quantity ?? -$issued->quantity_base, $request, $at, ['corrects_movement_id' => $issued->getKey()]);
    }

    /**
     * A final snapshot with numbered lines. Lines: [item, quantity_base, value_source?, through?]. Version follows finalization order.
     *
     * @param  list<array{0: SupplyItem, 1: int|null, 2?: string, 3?: int|null}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    protected function sdSnapshot(string $kind, ?string $origin, string $finalizedAt, array $lines, int $through, array $attributes = []): InventorySnapshot
    {
        $this->sdSequence++;
        $snapshot = InventorySnapshot::query()->create($attributes + [
            'uuid' => (string) Str::uuid(), 'kind' => $kind, 'origin' => $origin, 'status' => InventorySnapshot::DRAFT,
            'started_at' => $this->sdUtc($finalizedAt), 'through_movement_id' => $through,
        ]);
        foreach ($lines as $line) {
            InventorySnapshotLine::query()->create([
                'snapshot_id' => $snapshot->getKey(), 'supply_item_id' => $line[0]->getKey(), 'item_name' => $line[0]->name, 'quantity_base' => $line[1],
                'value_source' => $line[2] ?? ($line[1] === null ? InventorySnapshotLine::NOT_COUNTED : InventorySnapshotLine::COUNTED),
                'through_movement_id' => $line[3] ?? null,
            ]);
        }
        $snapshot->update([
            'status' => InventorySnapshot::FINAL, 'version' => (int) InventorySnapshot::query()->max('version') + 1,
            'finalized_at' => $this->sdUtc($finalizedAt), 'cutoff_at' => $this->sdUtc($finalizedAt), 'finalized_name' => 'Test Counter',
        ]);

        return $snapshot;
    }

    /** A physical count (the Monday count form). */
    protected function sdCount(string $finalizedAt, array $lines, int $through): InventorySnapshot
    {
        return $this->sdSnapshot(InventorySnapshot::KIND_COUNT, InventorySnapshot::ORIGIN_COUNT_FORM, $finalizedAt, $lines, $through);
    }

    /** The best-guess estimate baseline (never a count). */
    protected function sdEstimate(string $finalizedAt, array $lines, int $through = 0): InventorySnapshot
    {
        return $this->sdSnapshot(InventorySnapshot::KIND_BASELINE, InventorySnapshot::ORIGIN_ESTIMATE, $finalizedAt, $lines, $through);
    }

    protected function sdMaxMovementId(): int
    {
        return (int) StockMovement::query()->max('id');
    }
}
