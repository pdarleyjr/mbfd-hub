<?php

declare(strict_types=1);

namespace App\Support\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Services\PersonnelRequests\UniformOrderCatalog;

/**
 * When a member may change their own uniform order, and how a change is recorded.
 *
 * A member edits only an order they placed for themselves, only before Support Services
 * has ordered, received or issued anything for it. Every change is written to the
 * request's history with the items before and after, so nothing is lost.
 */
final class UniformOrderAmendment
{
    public const EVENT = 'member_amended';

    public const MEMBER_NOTE = 'Order updated by member.';

    /** Statuses in which nothing has been ordered, received or issued yet. */
    public const EDITABLE_STATUSES = [
        PersonnelRequestStatus::Pending,
        PersonnelRequestStatus::Acknowledged,
        PersonnelRequestStatus::NeedsInformation,
    ];

    private const LOCKED_ITEM_STATUSES = ['arrived', 'partially_arrived', 'fulfilled', 'partially_fulfilled'];

    /**
     * Why this member cannot edit the order right now, or null when they can.
     *
     * @param  iterable<PersonnelRequestItem>|null  $items  pass locked rows when changing the order
     */
    public static function blockReason(?Employee $employee, PersonnelRequest $request, ?iterable $items = null): ?string
    {
        if ($request->type !== PersonnelRequestType::Uniform
            || ! PersonnelRequestParticipantAccess::isBeneficiary($employee, $request)
            || ! PersonnelRequestParticipantAccess::isRequester($employee, $request)) {
            return 'Only the member who placed this uniform order can edit it.';
        }
        if ($request->trashed() || $request->isArchived()) {
            return 'This order is closed and can no longer be edited.';
        }
        if (! in_array($request->status, self::EDITABLE_STATUSES, true)) {
            return 'Support Services is already working on this order. Send them a message to change it.';
        }
        $items = collect($items ?? $request->items()->get());
        $receivedOrIssued = $items->contains(fn (PersonnelRequestItem $item): bool => (int) $item->arrived_quantity > 0
            || (int) $item->fulfilled_quantity > 0
            || in_array($item->fulfillment_status, self::LOCKED_ITEM_STATUSES, true));
        if ($receivedOrIssued || ($items->isNotEmpty()
            && AssignedEquipment::query()->whereIn('source_personnel_request_item_id', $items->pluck('id'))->exists())) {
            return 'Items on this order have already arrived or been issued. Send Support Services a message to change it.';
        }
        $orderable = app(UniformOrderCatalog::class)->selfServiceProducts();
        $codes = $items->pluck('item_code');
        if ($codes->contains(fn ($code): bool => ! array_key_exists((string) $code, $orderable)) || $codes->duplicates()->isNotEmpty()) {
            return 'This order has items only Support Services can change. Send them a message to change it.';
        }

        return null;
    }

    public static function canEdit(?Employee $employee, PersonnelRequest $request): bool
    {
        return self::blockReason($employee, $request) === null;
    }

    /**
     * A plain copy of the order lines, kept in the history so every version can be read back.
     *
     * @param  iterable<PersonnelRequestItem|array<string, mixed>>  $items
     * @return list<array{item_code: string, item_name: string, size: string|null, quantity: int, metadata: array<string, mixed>|null}>
     */
    public static function snapshot(iterable $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $row = $item instanceof PersonnelRequestItem ? $item->only(['item_code', 'item_name', 'size', 'quantity', 'metadata']) : $item;
            $lines[] = [
                'item_code' => (string) ($row['item_code'] ?? ''),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'size' => isset($row['size']) ? (string) $row['size'] : null,
                'quantity' => (int) ($row['quantity'] ?? 0),
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : null,
            ];
        }

        return $lines;
    }

    /**
     * Plain-language lines for what changed, for the member's and Support Services' history.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    public static function describeChanges(array $before, array $after, ?string $noteBefore = null, ?string $noteAfter = null): array
    {
        $old = collect($before)->keyBy('item_code');
        $new = collect($after)->keyBy('item_code');
        $lines = [];
        foreach ($new as $code => $line) {
            $previous = $old->get($code);
            if ($previous === null) {
                $lines[] = 'Added '.self::describeLine($line).'.';

                continue;
            }
            $parts = [];
            if ((int) $previous['quantity'] !== (int) $line['quantity']) {
                $parts[] = "quantity {$previous['quantity']} to {$line['quantity']}";
            }
            if ((string) $previous['size'] !== (string) $line['size']) {
                $parts[] = 'size '.($previous['size'] ?: 'none').' to '.($line['size'] ?: 'none');
            } elseif (($previous['metadata'] ?? null) != ($line['metadata'] ?? null)) {
                $parts[] = 'ordering details changed';
            }
            if ($parts !== []) {
                $lines[] = "{$line['item_name']}: ".implode(', ', $parts).'.';
            }
        }
        foreach ($old->diffKeys($new) as $line) {
            $lines[] = 'Removed '.self::describeLine($line).'.';
        }
        if (trim((string) $noteBefore) !== trim((string) $noteAfter)) {
            $lines[] = 'Notes for Support Services changed.';
        }

        return $lines;
    }

    /** @param array<string, mixed> $line */
    private static function describeLine(array $line): string
    {
        return "{$line['item_name']} × {$line['quantity']}".(filled($line['size'] ?? null) ? " (size {$line['size']})" : '');
    }
}
