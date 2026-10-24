<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Physical counts by location (docs/ems/ems-inventory-contract.md section 6): a draft that
 * can be resumed, then a final snapshot that is immutable and attributed to the counter and the
 * time. Typed entries may be cases / boxes (packages) / each; conversion uses only explicit
 * factors, otherwise the line is flagged and its base quantity is left empty (never guessed).
 */
final class InventoryCountService
{
    private const ENTRY_MAX = 999999;

    /** Package units that a typed "boxes" count can use with units_per_package. */
    private const BOX_LIKE = ['box', 'pack', 'bag'];

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly StockLedger $ledger,
        private readonly EmsAccess $access,
        private readonly SnapshotPresenter $presenter,
    ) {}

    /**
     * Start a count, or resume the open draft for the same location.
     *
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function start(User $user, string $key, mixed $locationCode, mixed $notes): array
    {
        $this->authorize($user);
        $checked = EmsValidator::validate(['location_code' => $locationCode, 'notes' => $notes], [
            'location_code' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['location_code.max' => 'That storage location code is too long.', 'notes.max' => 'Notes can be at most 1000 characters.']);
        $locationCode = $checked['location_code'];
        $notes = $checked['notes'];
        $code = $locationCode !== null && trim($locationCode) !== '' ? trim($locationCode) : null;
        if ($code !== null && ! StorageLocation::query()->where('code', $code)->where('active', true)->exists()) {
            throw new EmsException('That storage location does not exist.', 'unknown_location', 422);
        }

        return $this->idempotency->run($user, 'ems.count.start', $key, function () use ($user, $code, $notes): array {
            $this->ledger->lock();
            $draft = InventorySnapshot::query()->where('kind', InventorySnapshot::KIND_COUNT)->where('status', InventorySnapshot::DRAFT)
                ->where('scope_location_code', $code)->orderBy('id')->first();
            $resumed = $draft !== null;
            $draft ??= InventorySnapshot::query()->create([
                'uuid' => (string) Str::uuid(), 'kind' => InventorySnapshot::KIND_COUNT, 'status' => InventorySnapshot::DRAFT,
                'scope_location_code' => $code, 'started_by_user_id' => $user->getKey(), 'started_at' => now(),
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ]);

            return $this->presenter->detail($draft, withItems: true) + ['resumed' => $resumed];
        });
    }

    /**
     * Save typed entries into a draft. A line with every entry blank removes that line.
     *
     * @param  array<string, mixed>  $input  {lines: [{supply_item_id, cases?, boxes?, each?}]}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function saveLines(int $snapshotId, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.supply_item_id' => ['required', 'integer'],
            'lines.*.cases' => ['nullable', 'integer', 'min:0', 'max:'.self::ENTRY_MAX],
            'lines.*.boxes' => ['nullable', 'integer', 'min:0', 'max:'.self::ENTRY_MAX],
            'lines.*.each' => ['nullable', 'integer', 'min:0', 'max:'.self::ENTRY_MAX],
        ], ['lines.required' => 'Enter at least one count.', 'lines.*.each.integer' => 'Counts must be whole numbers.', 'lines.*.boxes.integer' => 'Counts must be whole numbers.', 'lines.*.cases.integer' => 'Counts must be whole numbers.']);

        return $this->idempotency->run($user, "ems.count.save.{$snapshotId}", $key, function () use ($snapshotId, $data): array {
            // A count is a statement about the shelf at the moment it is typed. Remember which movements
            // already existed then, so an issuance made while the draft stays open is not lost at finalize.
            $this->ledger->lock();
            $through = $this->ledger->maxMovementId();
            $snapshot = $this->draft($snapshotId);
            $items = SupplyItem::query()->with('location:id,code')->whereIn('id', collect($data['lines'])->pluck('supply_item_id')->all())->get()->keyBy('id');

            foreach ($data['lines'] as $row) {
                $item = $items->get((int) $row['supply_item_id']);
                if ($item === null || ! $item->tracksStock()) {
                    throw new EmsException('One of those items is not counted in the supply room (controlled substances and inactive items are not tracked).', 'item_not_counted', 422);
                }
                if ($snapshot->scope_location_code !== null && $item->location?->code !== $snapshot->scope_location_code) {
                    throw new EmsException("\"{$item->name}\" is not in location {$snapshot->scope_location_code}.", 'wrong_location', 422);
                }
                $entry = array_filter(
                    ['cases' => $row['cases'] ?? null, 'boxes' => $row['boxes'] ?? null, 'each' => $row['each'] ?? null],
                    fn ($value): bool => $value !== null,
                );
                $existing = InventorySnapshotLine::query()->where('snapshot_id', $snapshot->getKey())->where('supply_item_id', $item->getKey())->first();
                if ($entry === []) { // nothing typed: the line is cleared; a typed 0 is a real count of zero
                    $existing?->delete();

                    continue;
                }
                $converted = self::convertEntry($item, $entry);
                if ($converted['quantity'] !== null) {
                    StockLedger::assertWithinLimit($converted['quantity']);
                }
                // Re-sending an unchanged entry (a draft autosave) must not move its baseline forward.
                $unchanged = $existing !== null && ($existing->entry ?? []) == $entry && $existing->through_movement_id !== null;
                InventorySnapshotLine::query()->updateOrCreate(
                    ['snapshot_id' => $snapshot->getKey(), 'supply_item_id' => $item->getKey()],
                    [
                        'location_code' => $item->location?->code, 'item_name' => $item->name,
                        'quantity_base' => $converted['quantity'], 'entry' => $entry,
                        'value_source' => $converted['quantity'] === null ? InventorySnapshotLine::NOT_COUNTED : InventorySnapshotLine::COUNTED,
                        'warnings' => $converted['warnings'] === [] ? null : $converted['warnings'],
                        'through_movement_id' => $unchanged ? $existing->through_movement_id : $through,
                    ],
                );
            }

            return $this->presenter->detail($snapshot->fresh(), withItems: true);
        });
    }

    /** @return array{result: array<string, mixed>, replayed: bool} */
    public function finalize(int $snapshotId, User $user, string $key): array
    {
        $this->authorize($user);

        return $this->idempotency->run($user, "ems.count.finalize.{$snapshotId}", $key, function () use ($snapshotId, $user): array {
            $this->ledger->lock();
            $snapshot = $this->draft($snapshotId);
            if (! $snapshot->lines()->whereNotNull('quantity_base')->exists()) {
                throw new EmsException('Nothing has been counted yet. Enter at least one count before you finalize.', 'empty_count', 422);
            }
            $previous = InventorySnapshot::query()->where('status', InventorySnapshot::FINAL)->orderByDesc('version')->first();
            $actor = EmsActor::forUser($user, 'captain5');

            $snapshot->update([
                'status' => InventorySnapshot::FINAL,
                'version' => (int) InventorySnapshot::query()->max('version') + 1,
                'finalized_by_user_id' => $user->getKey(), 'finalized_name' => $actor->name,
                'finalized_at' => now(), 'cutoff_at' => now(),
                'through_movement_id' => $this->ledger->maxMovementId(),
                'previous_snapshot_id' => $previous?->getKey(),
            ]);

            return $this->presenter->detail($snapshot->fresh(), withItems: false);
        });
    }

    /** Throw away a draft nobody wants (a final snapshot can never be deleted). */
    public function discard(int $snapshotId, User $user, string $key): array
    {
        $this->authorize($user);

        return $this->idempotency->run($user, "ems.count.discard.{$snapshotId}", $key, function () use ($snapshotId): array {
            $snapshot = $this->draft($snapshotId);
            $snapshot->lines()->delete();
            $snapshot->delete();

            return ['discarded' => true, 'id' => $snapshotId];
        });
    }

    /**
     * Convert typed counts to base units using only explicit factors.
     *
     * @param  array<string, int>  $entry  cases / boxes / each
     * @return array{quantity: int|null, warnings: list<string>}
     */
    public static function convertEntry(SupplyItem $item, array $entry): array
    {
        $total = (int) ($entry['each'] ?? 0);
        $warnings = [];
        $perPackage = $item->units_per_package;

        if ((int) ($entry['boxes'] ?? 0) > 0) {
            // A "box" count needs a box-sized package. A case-packaged item has no explicit box factor.
            in_array($item->package_unit, self::BOX_LIKE, true) && $perPackage !== null
                ? $total += (int) $entry['boxes'] * $perPackage
                : $warnings[] = 'unknown_conversion';
        }
        if ((int) ($entry['cases'] ?? 0) > 0) {
            if ($item->package_unit === 'case' && $perPackage !== null) {
                $total += (int) $entry['cases'] * $perPackage;
            } elseif ($item->packages_per_case !== null && $perPackage !== null) {
                $total += (int) $entry['cases'] * $item->packages_per_case * $perPackage;
            } else {
                $warnings[] = 'unknown_conversion';
            }
        }

        return $warnings === [] ? ['quantity' => $total, 'warnings' => []] : ['quantity' => null, 'warnings' => array_values(array_unique($warnings))];
    }

    private function draft(int $snapshotId): InventorySnapshot
    {
        $snapshot = InventorySnapshot::query()->whereKey($snapshotId)->lockForUpdate()->first();
        if ($snapshot === null || $snapshot->kind !== InventorySnapshot::KIND_COUNT) {
            throw EmsException::notFound('count');
        }
        if ($snapshot->isFinal()) {
            throw EmsException::conflict('This count is final and cannot be changed.', 'count_final');
        }

        return $snapshot;
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canAct($user)) {
            throw EmsException::forbidden();
        }
    }
}
