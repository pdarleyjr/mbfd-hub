<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyOrder;
use App\Models\Ems\SupplyOrderEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Ordering from the master inventory (docs/ems/captain5-medical-inventory-workflow.md section 11).
 * An item has at most one open order: pending, ordered or back ordered. "Arrived" adds what came in to stock and
 * closes the order, so the item's to-order flag clears by itself once on hand reaches the expected amount.
 * Quantities are in the item's count unit. Every change is kept in ems_supply_order_events. Captain 5 and any admin.
 */
final class SupplyItemOrders
{
    /** What the master list says => what is stored. */
    private const STATUSES = ['pending' => SupplyOrder::PENDING, 'ordered' => SupplyOrder::PLACED, 'backordered' => SupplyOrder::BACKORDERED];

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly StockLedger $ledger,
        private readonly EmsAccess $access,
        private readonly MasterInventoryService $master,
    ) {}

    /**
     * @param  array<string, mixed>  $input  {status: pending|ordered|backordered, quantity, expected_on?, note?}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function open(int $itemId, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, [
            'status' => ['required', 'in:'.implode(',', array_keys(self::STATUSES))],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ] + $this->detailRules(), $this->messages());

        return $this->idempotency->run($user, "ems.item.order.{$itemId}", $key, function () use ($itemId, $user, $data): array {
            $this->ledger->lock();
            $item = $this->item($itemId);
            if (SupplyOrder::query()->where('supply_item_id', $item->getKey())->whereIn('status', SupplyOrder::OPEN)->lockForUpdate()->exists()) {
                throw EmsException::conflict("\"{$item->name}\" is already on order. Update that order instead.", 'order_open');
            }
            $actor = EmsActor::forUser($user, 'captain5');
            $base = $this->toBase($item, (int) $data['quantity']);
            $order = SupplyOrder::query()->create([
                'supply_item_id' => $item->getKey(), 'status' => self::STATUSES[$data['status']], 'packages' => (int) $data['quantity'],
                'order_unit' => $item->count_unit, 'quantity_base' => $base, 'expected_on' => $data['expected_on'] ?? null,
                'notes' => $this->note($data), 'placed_by_user_id' => $user->getKey(), 'placed_by_name' => $actor->name, 'placed_at' => now(),
                'status_changed_at' => now(), 'status_changed_by_name' => $actor->name,
            ]);
            $this->event($order, null, $order->status, $base, $actor, $this->note($data));

            return $this->master->row((int) $item->getKey());
        });
    }

    /**
     * @param  array<string, mixed>  $input  {status?: pending|ordered|backordered|cancelled, quantity?, expected_on?, note?}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function update(int $orderId, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, [
            'status' => ['sometimes', 'in:'.implode(',', [...array_keys(self::STATUSES), 'cancelled'])],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ] + $this->detailRules(), $this->messages());

        return $this->idempotency->run($user, "ems.item.order.update.{$orderId}", $key, function () use ($orderId, $user, $data): array {
            $this->ledger->lock();
            $order = $this->openOrder($orderId);
            $cancelling = ($data['status'] ?? null) === 'cancelled';
            // Cancelling must work even after the item was taken off the list; anything else needs a live item.
            $item = $cancelling ? SupplyItem::query()->findOrFail($order->supply_item_id) : $this->item((int) $order->supply_item_id);
            $actor = EmsActor::forUser($user, 'captain5');
            $from = $order->status;
            $to = ($data['status'] ?? null) === 'cancelled' ? SupplyOrder::CANCELLED : (isset($data['status']) ? self::STATUSES[$data['status']] : $from);

            $changes = ['status' => $to, 'status_changed_at' => now(), 'status_changed_by_name' => $actor->name];
            if (isset($data['quantity']) && ! $cancelling) {
                $changes += ['packages' => (int) $data['quantity'], 'order_unit' => $item->count_unit, 'quantity_base' => $this->toBase($item, (int) $data['quantity'])];
            }
            if (array_key_exists('expected_on', $data)) {
                $changes['expected_on'] = $data['expected_on'];
            }
            if (array_key_exists('note', $data)) {
                $changes['notes'] = $this->note($data);
            }
            if ($to === SupplyOrder::CANCELLED) {
                $changes += ['cancelled_by_user_id' => $user->getKey(), 'cancelled_at' => now()];
            }
            $order->update($changes);
            $this->event($order, $from, $to, $order->quantity_base, $actor, $this->note($data));

            return $item->tracksStock() ? $this->master->row((int) $item->getKey()) : ['id' => $item->getKey(), 'cancelled_order_id' => $order->getKey()];
        });
    }

    /**
     * @param  array<string, mixed>  $input  {quantity: count units that arrived, note?}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function arrived(int $orderId, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, ['quantity' => ['required', 'integer', 'min:1', 'max:100000'], 'note' => ['nullable', 'string', 'max:1000']], $this->messages());

        return $this->idempotency->run($user, "ems.item.order.arrived.{$orderId}", $key, function () use ($orderId, $user, $data): array {
            $this->ledger->lock();
            $order = $this->openOrder($orderId);
            $item = $this->item((int) $order->supply_item_id);
            $actor = EmsActor::forUser($user, 'captain5');
            $base = $this->toBase($item, (int) $data['quantity']);

            $this->ledger->record($item, StockMovement::RECEIVED, $base, $actor, 'receive:order:'.$order->getKey(), 'Order arrived', ['supply_order_id' => $order->getKey()]);
            $from = $order->status;
            // The closed order records what actually arrived; what was ordered stays in its first event.
            $order->update([
                'packages' => (int) $data['quantity'], 'quantity_base' => $base, 'order_unit' => $item->count_unit,
                'status' => SupplyOrder::RECEIVED, 'received_by_user_id' => $user->getKey(), 'received_at' => now(),
                'status_changed_at' => now(), 'status_changed_by_name' => $actor->name,
            ]);
            $this->event($order, $from, SupplyOrder::RECEIVED, $base, $actor, $this->note($data));

            return $this->master->row((int) $item->getKey());
        });
    }

    private function item(int $itemId): SupplyItem
    {
        $item = SupplyItem::query()->find($itemId);
        if ($item === null || ! $item->tracksStock()) {
            throw new EmsException('That item is not counted in the supply room.', 'item_not_counted', 422);
        }

        return $item;
    }

    private function openOrder(int $orderId): SupplyOrder
    {
        $order = SupplyOrder::query()->whereKey($orderId)->lockForUpdate()->first() ?? throw EmsException::notFound('order');
        if (! $order->isOpen()) {
            throw EmsException::conflict($order->status === SupplyOrder::RECEIVED ? 'This order already arrived.' : 'This order was cancelled.', 'order_not_open');
        }

        return $order;
    }

    private function toBase(SupplyItem $item, int $quantity): int
    {
        CountMath::assertConvertible($item);
        $base = CountMath::toBase($item, $quantity);
        StockLedger::assertWithinLimit($base);

        return $base;
    }

    private function event(SupplyOrder $order, ?string $from, string $to, ?int $quantityBase, EmsActor $actor, ?string $note): void
    {
        SupplyOrderEvent::query()->create([
            'supply_order_id' => $order->getKey(), 'from_status' => $from, 'to_status' => $to, 'quantity_base' => $quantityBase,
            'actor_user_id' => $actor->userId, 'actor_name' => $actor->name, 'note' => $note, 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function note(array $data): ?string
    {
        $note = trim((string) ($data['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    /** @return array<string, list<string>> */
    private function detailRules(): array
    {
        return [
            'expected_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now('America/New_York')->subYear()->toDateString()],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'status.required' => 'Choose pending, ordered or back ordered.', 'status.in' => 'Choose pending, ordered or back ordered.',
            'quantity.required' => 'Enter how many.', 'quantity.integer' => 'Enter a whole number.', 'quantity.min' => 'Enter at least 1.',
            'quantity.max' => 'That number is too large. Check it.', 'expected_on.date_format' => 'Enter the date as year-month-day.',
            'expected_on.after_or_equal' => 'That date is too far in the past.', 'note.max' => 'Keep the note to 1,000 characters.',
        ];
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canEditInventory($user)) {
            throw EmsException::forbidden();
        }
    }
}
