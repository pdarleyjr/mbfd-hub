<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person recorded that an order was placed. "Reorder recommended" is computed and never creates one.
 *
 * @property int $id
 * @property int $supply_item_id
 * @property string $status
 * @property int $packages
 * @property int|null $quantity_base
 * @property string|null $supplier_code
 * @property string|null $item_number
 * @property int|null $placed_by_user_id
 * @property string|null $placed_by_name
 * @property \Illuminate\Support\Carbon $placed_at
 * @property int|null $received_by_user_id
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property int|null $cancelled_by_user_id
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $order_unit
 * @property \Illuminate\Support\Carbon|null $expected_on
 * @property \Illuminate\Support\Carbon|null $status_changed_at
 * @property string|null $status_changed_by_name
 * @property string|null $notes
 * @property string|null $idempotency_key
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SupplyOrder extends Model
{
    /** Wanted, not yet placed with the supplier. */
    public const PENDING = 'pending';

    /** Shown as "Ordered". */
    public const PLACED = 'placed';

    public const BACKORDERED = 'backordered';

    /** Shown as "Arrived": the amount that came in was added to stock. */
    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    /** Still waiting on something; an item has at most one of these. */
    public const OPEN = [self::PENDING, self::PLACED, self::BACKORDERED];

    protected $table = 'ems_supply_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'packages' => 'integer',
            'quantity_base' => 'integer',
            'placed_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expected_on' => 'date',
            'status_changed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<SupplyOrderEvent, $this> */
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplyOrderEvent::class, 'supply_order_id');
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }
}
