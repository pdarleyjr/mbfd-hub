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
        return $this->belongsTo(InventoryItem::class , 'inventory_item_id');
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
    /**
     * Scope to get items with overstocked status
     */
    public function scopeOverstocked($query)
    {
        return $query->where('status', 'overstocked');
    }

    /**
     * Update the on_hand count and automatically set the status based on par/logic.
     * 
     * @param int $newCount
     * @return void
     */
    public function updateCount(int $newCount): void
    {
        $this->on_hand = $newCount;
        $this->last_updated_at = now();

        $par = $this->inventoryItem->par_quantity;
        $lowThreshold = $this->inventoryItem->effective_low_threshold;

        // Logic:
        // 1. If currently 'ordered' and count increases but still below par -> stay 'ordered' (partial fill)??
        //    Actually, user said: "Once replenished, the on hand count should automatically be changed to match the expected count."
        //    So if we are setting it manually, we should probably reset status unless it's still low?
        //    Let's stick to the requested logic:
        //    - If newCount <= lowThreshold -> 'low' (unless already 'ordered', maybe? User said "allowed more time before having to order more" implying manual 'ordered' status is sticky until replenished).
        //    - If newCount > par -> 'overstocked'.
        //    - Else -> 'ok'.

        if ($newCount > $par) {
            $this->status = 'overstocked';
        }
        elseif ($newCount <= $lowThreshold) {
            // Only set to low if not already ordered, or if we want to re-alert?
            // "The admin can change the supply to 'ordered' and then clear the alert once replenished."
            // So if it is 'ordered', we should probably leave it as 'ordered' unless the count goes up enough to be OK.
            if ($this->status !== 'ordered') {
                $this->status = 'low';
            }
        }
        else {
            // Count is > lowThreshold and <= par
            $this->status = 'ok';
        }

        $this->save();
    }
}
