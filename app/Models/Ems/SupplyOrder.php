<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person recorded that an order was placed. "Reorder recommended" is computed and never creates one. */
class SupplyOrder extends Model
{
    public const PLACED = 'placed';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

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
        ];
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }
}
