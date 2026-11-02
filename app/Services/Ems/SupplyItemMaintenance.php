<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use App\Models\User;

/**
 * Threshold, packaging, location and review-flag edits by an authorized person (captain5-admin or
 * admin.ems.manage). Every changed field writes one audit row with who, when, before, after and the reason.
 */
final class SupplyItemMaintenance
{
    private const PACKAGE_UNITS = ['each', 'box', 'case', 'pack', 'bag'];

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly EmsAccess $access,
        private readonly SupplyRoomQuery $query,
    ) {}

    /**
     * @param  array<string, mixed>  $input  any of threshold_qty_base, units_per_package, packages_per_case, package_unit,
     *                                       order_qty_packages, location_code, resolve_review, active; plus reason (required)
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function update(int $itemId, User $user, string $key, array $input): array
    {
        if (! $this->access->canConfigure($user)) {
            throw EmsException::forbidden();
        }
        $data = EmsValidator::validate($input, [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'threshold_qty_base' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'units_per_package' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'packages_per_case' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'package_unit' => ['sometimes', 'nullable', 'in:'.implode(',', self::PACKAGE_UNITS)],
            'order_qty_packages' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'location_code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'resolve_review' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ], ['reason.required' => 'Give a reason for this change so the audit trail makes sense.', 'reason.min' => 'Give a reason for this change so the audit trail makes sense.']);

        return $this->idempotency->run($user, "ems.item.update.{$itemId}", $key, function () use ($itemId, $user, $data): array {
            $item = SupplyItem::query()->whereKey($itemId)->lockForUpdate()->first() ?? throw EmsException::notFound('supply item');
            $actor = EmsActor::forUser($user, 'captain5');
            $changes = [];

            foreach (['threshold_qty_base', 'units_per_package', 'packages_per_case', 'package_unit', 'order_qty_packages', 'active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $data[$field] === null ? null : (is_bool($data[$field]) ? $data[$field] : (is_numeric($data[$field]) ? (int) $data[$field] : $data[$field]));
                }
            }
            if (array_key_exists('location_code', $data)) {
                $code = $data['location_code'] === null || trim((string) $data['location_code']) === '' ? null : trim((string) $data['location_code']);
                $location = $code === null ? null : StorageLocation::query()->where('code', $code)->first();
                if ($code !== null && $location === null) {
                    throw new EmsException('That storage location does not exist.', 'unknown_location', 422);
                }
                $changes['location_id'] = $location?->getKey();
            }
            if (($data['resolve_review'] ?? false) === true) {
                $changes['needs_review'] = false;
                $changes['review_reasons'] = [];
            }

            // Packaging is what makes a typed count convert, so it cannot change out from under an item counted in packages.
            $packageUnit = array_key_exists('package_unit', $changes) ? $changes['package_unit'] : $item->package_unit;
            $perPackage = array_key_exists('units_per_package', $changes) ? $changes['units_per_package'] : $item->units_per_package;
            if ($item->count_unit !== 'each' && ($packageUnit !== $item->count_unit || $perPackage === null)) {
                throw new EmsException("This item is counted by the {$item->count_unit}. Change how it is counted on the Master Supply Room Inventory first.", 'counted_in_package', 422);
            }

            $written = [];
            foreach ($changes as $field => $after) {
                $before = $item->getAttribute($field);
                if ($before === $after || $before == $after && $before !== null && $after !== null) {
                    continue;
                }
                SupplyItemEvent::query()->create([
                    'supply_item_id' => $item->getKey(), 'user_id' => $actor->userId, 'actor_name' => $actor->name, 'field' => $field,
                    'before' => ['value' => $before], 'after' => ['value' => $after], 'reason' => trim($data['reason']),
                ]);
                $item->setAttribute($field, $after);
                $written[] = $field;
            }
            if ($written === []) {
                throw new EmsException('Nothing changed. Enter a different value.', 'no_change', 422);
            }
            $item->save();

            return $this->query->item($item->fresh()) + ['changed_fields' => $written];
        });
    }
}
