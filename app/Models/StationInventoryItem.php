<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationInventoryItem extends Model
{
    protected $fillable = [
        'station_id',
        'inventory_item_id',
        'on_hand',
        'status',
        'last_updated_at',
    ];

    protected $casts = [
        'on_hand' => 'integer',
        'last_updated_at' => 'datetime',
    ];

    /**
     * Get the station this inventory belongs to
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the inventory item
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * Scope to get items with low stock
     */
    public function scopeLowStock($query)
    {
        return $query->where('status', 'low');
    }

    /**
     * Scope to get items that have been ordered
     */
    public function scopeOrdered($query)
    {
        return $query->where('status', 'ordered');
    }

    /**
     * Scope to get items with ok status
     */
    public function scopeOk($query)
    {
        return $query->where('status', 'ok');
    }
}
