<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationInventoryAudit extends Model
{
    const UPDATED_AT = null; // Only use created_at

    protected $fillable = [
        'station_id',
        'inventory_item_id',
        'actor_name',
        'actor_shift',
        'action',
        'from_value',
        'to_value',
    ];

    protected $casts = [
        'from_value' => 'array',
        'to_value' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the station this audit belongs to
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the inventory item (nullable)
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * Scope to get audits for a specific station
     */
    public function scopeForStation($query, $stationId)
    {
        return $query->where('station_id', $stationId);
    }

    /**
     * Scope to get recent audits
     */
    public function scopeRecent($query, $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
