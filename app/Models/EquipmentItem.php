<?php

namespace App\Models;

use Appstract\Stock\HasStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EquipmentItem extends Model
{
    use HasStock;

    protected $fillable = [
        'name',
        'normalized_name',
        'category',
        'description',
        'manufacturer',
        'unit_of_measure',
        'reorder_min',
        'reorder_max',
        'location_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reorder_min' => 'integer',
        'reorder_max' => 'integer',
    ];

    /**
     * Boot method to auto-generate normalized_name
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            if ($item->name) {
                $item->normalized_name = self::normalizeName($item->name);
            }
        });
    }

    /**
     * Normalize item name for matching
     */
    public static function normalizeName(string $name): string
    {
        // Lowercase, trim, collapse whitespace, remove punctuation
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);
        
        return $normalized;
    }

    /**
     * Get location relationship
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    /**
     * Get all recommendations for this item
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(ApparatusDefectRecommendation::class, 'equipment_item_id');
    }

    /**
     * Get all allocations of this item
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ApparatusInventoryAllocation::class, 'equipment_item_id');
    }

    /**
     * Check if stock is low
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->reorder_min;
    }

    /**
     * Get current stock level (from laravel-stock)
     */
    public function getCurrentStockAttribute(): int
    {
        return $this->stock ?? 0;
    }
}
