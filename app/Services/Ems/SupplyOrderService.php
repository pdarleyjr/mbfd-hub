<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyOrder;
use App\Models\User;

/**
 * Supply orders and manual stock adjustments. An order is recorded only when a person says it was
 * placed; receiving it writes one `received` movement; a manual adjustment is an `adjusted` movement
 * with a required reason.
 */
final class SupplyOrderService
{
    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly StockLedger $ledger,
        private readonly EmsAccess $access,
        private readonly SupplyRoomQuery $query,
    ) {}

    /**
     * @param  array<string, mixed>  $input  {supply_item_id, packages, supplier_code?, item_number?, notes?}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function place(User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, [
            'supply_item_id' => ['required', 'integer'],
            'packages' => ['required', 'integer', 'min:1', 'max:100000'],
            'supplier_code' => ['nullable', 'string', 'max:40'],
            'item_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['packages.required' => 'Enter how many packages were ordered.', 'packages.min' => 'Enter how many packages were ordered.']);

        return $this->idempotency->run($user, 'ems.order.place', $key, function () use ($user, $data, $key): array {
            $item = $this->trackedItem((int) $data['supply_item_id']);
            if ($item->units_per_package !== null) {
                StockLedger::assertWithinLimit((int) $data['packages'] * $item->units_per_package);
            }
            $actor = EmsActor::forUser($user, 'captain5');
            $order = SupplyOrder::query()->create([
                'supply_item_id' => $item->getKey(), 'status' => SupplyOrder::PLACED, 'packages' => (int) $data['packages'],
                'quantity_base' => $item->units_per_package !== null ? (int) $data['packages'] * $item->units_per_package : null,
                'supplier_code' => $data['supplier_code'] ?? null, 'item_number' => $data['item_number'] ?? null,
                'placed_by_user_id' => $user->getKey(), 'placed_by_name' => $actor->name, 'placed_at' => now(),
                'notes' => $data['notes'] ?? null, 'idempotency_key' => 'place:'.$user->getKey().':'.$key,
            ]);

            return $this->query->order($order->load('supplyItem:id,name'));
        });
    }

    /**
     * @param  array<string, mixed>  $input  {quantity_base?} required when the package size is unknown
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function receive(int $orderId, User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, ['quantity_base' => ['nullable', 'integer', 'min:1', 'max:10000000']], ['quantity_base.min' => 'Enter how many units were received.']);

        return $this->idempotency->run($user, "ems.order.receive.{$orderId}", $key, function () use ($orderId, $user, $data): array {
            $this->ledger->lock();
            $order = SupplyOrder::query()->whereKey($orderId)->lockForUpdate()->first() ?? throw EmsException::notFound('order');
            if ($order->status !== SupplyOrder::PLACED) {
                throw EmsException::conflict($order->status === SupplyOrder::RECEIVED ? 'This order was already received.' : 'This order was cancelled.', 'order_not_open');
            }
            $quantity = isset($data['quantity_base']) ? (int) $data['quantity_base'] : $order->quantity_base;
            if ($quantity === null) {
                throw new EmsException('The package size for this item is not known, so enter how many individual units were received.', 'quantity_needed', 422, ['quantity_base' => ['Enter how many individual units were received.']]);
            }
            $item = SupplyItem::query()->findOrFail($order->supply_item_id);
            $actor = EmsActor::forUser($user, 'captain5');

            $this->ledger->record($item, StockMovement::RECEIVED, $quantity, $actor, 'receive:order:'.$order->getKey(), 'Order received', ['supply_order_id' => $order->getKey()]);
            $order->update(['status' => SupplyOrder::RECEIVED, 'received_by_user_id' => $user->getKey(), 'received_at' => now(), 'quantity_base' => $order->quantity_base ?? $quantity]);

            return $this->query->order($order->load('supplyItem:id,name'));
        });
    }

    /** @return array{result: array<string, mixed>, replayed: bool} */
    public function cancel(int $orderId, User $user, string $key): array
    {
        $this->authorize($user);

        return $this->idempotency->run($user, "ems.order.cancel.{$orderId}", $key, function () use ($orderId, $user): array {
            $order = SupplyOrder::query()->whereKey($orderId)->lockForUpdate()->first() ?? throw EmsException::notFound('order');
            if ($order->status !== SupplyOrder::PLACED) {
                throw EmsException::conflict('Only an order that is still open can be cancelled.', 'order_not_open');
            }
            $order->update(['status' => SupplyOrder::CANCELLED, 'cancelled_by_user_id' => $user->getKey(), 'cancelled_at' => now()]);

            return $this->query->order($order->load('supplyItem:id,name'));
        });
    }

    /**
     * A manual stock adjustment (signed units, reason required).
     *
     * @param  array<string, mixed>  $input  {supply_item_id, quantity_base, reason}
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function adjust(User $user, string $key, array $input): array
    {
        $this->authorize($user);
        $data = EmsValidator::validate($input, [
            'supply_item_id' => ['required', 'integer'],
            'quantity_base' => ['required', 'integer', 'not_in:0', 'between:-10000000,10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['quantity_base.not_in' => 'Enter a change other than zero.', 'reason.required' => 'Give a reason for the adjustment.', 'reason.min' => 'Give a reason for the adjustment.']);

        return $this->idempotency->run($user, 'ems.stock.adjust', $key, function () use ($user, $data, $key): array {
            $this->ledger->lock();
            $item = $this->trackedItem((int) $data['supply_item_id']);
            $movement = $this->ledger->record(
                $item, StockMovement::ADJUSTED, (int) $data['quantity_base'], EmsActor::forUser($user, 'captain5'),
                'adjust:'.$user->getKey().':'.$key, trim($data['reason']),
            );

            return ['movement_id' => $movement->getKey(), 'supply_item_id' => $item->getKey(), 'quantity_base' => $movement->quantity_base];
        });
    }

    private function trackedItem(int $id): SupplyItem
    {
        $item = SupplyItem::query()->find($id);
        if ($item === null || ! $item->active) {
            throw new EmsException('That supply room item could not be found.', 'unknown_supply_item', 422);
        }
        if ($item->controlled_substance) {
            throw new EmsException('Controlled substances are restocked through the department controlled-substance procedure and are not tracked here.', 'controlled_not_tracked', 422);
        }

        return $item;
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canAct($user)) {
            throw EmsException::forbidden();
        }
    }
}
