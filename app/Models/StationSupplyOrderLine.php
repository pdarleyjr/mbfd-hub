<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationSupplyOrderLine extends Model
{
    protected $fillable = [
        'station_supply_order_id',
        'station_id',
        'inventory_item_id',
        'station_inventory_item_id',
        'qty_suggested',
        'qty_ordered',
        'qty_delivered',
        'status',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StationSupplyOrder::class, 'station_supply_order_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function stationInventoryItem(): BelongsTo
    {
        return $this->belongsTo(StationInventoryItem::class);
    }
}
