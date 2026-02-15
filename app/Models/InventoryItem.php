<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'par_quantity',
        'low_threshold',
        'unit_label',
        'unit_multiplier',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'par_quantity' => 'integer',
        'low_threshold' => 'integer',
        'unit_multiplier' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Get the category this item belongs to
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class , 'category_id');
    }

    /**
     * Get all station inventory records for this item
     */
    public function stationInventories(): HasMany
    {
        return $this->hasMany(StationInventoryItem::class , 'inventory_item_id');
    }

    /**
     * Get all audit logs for this item
     */
    public function audits(): HasMany
    {
        return $this->hasMany(StationInventoryAudit::class , 'inventory_item_id');
    }

    /**
     * Scope to get only active items
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope to order by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get the expected quantity in the smallest unit (par_quantity * unit_multiplier)
     */
    public function getParUnitsAttribute(): int
    {
        $multiplier = $this->unit_multiplier ?? 1;
        return ($this->par_quantity ?? 0) * $multiplier;
    }

    /**
     * Get the effective low threshold, defaulting to 50% of par if not set
     */
    public function getEffectiveLowThresholdAttribute(): int
    {
        if ($this->low_threshold !== null) {
            return $this->low_threshold;
        }

        return (int)floor(($this->par_quantity ?? 0) / 2);
    }

    /**
     * Get the unit label, defaulting to 'units' if not set
     */
    public function getUnitLabelAttribute(): string
    {
        return $this->attributes['unit_label'] ?? 'units';
    }
}
