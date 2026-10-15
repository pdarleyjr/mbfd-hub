<?php

namespace App\Models;

use Appstract\Stock\HasStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentItem extends Model
{
    use HasStock {
        createStockMutation as protected createLedgerStockMutation;
    }

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

    /**
     * Current stock. Lists and dashboards preload it with withStockTotal();
     * otherwise laravel-stock sums the ledger on each access.
     */
    public function getStockAttribute(): int
    {
        if (array_key_exists('stock_total', $this->attributes)) {
            return (int) $this->attributes['stock_total'];
        }

        return $this->stock();
    }

    /** A new ledger entry makes any preloaded total stale. */
    protected function createStockMutation($amount, $arguments = [])
    {
        unset($this->attributes['stock_total']);

        return $this->createLedgerStockMutation($amount, $arguments);
    }

    /**
     * Preload each item's current stock in the list query (laravel-stock otherwise
     * runs one SUM query per access).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function withStockTotal(Builder $query): Builder
    {
        return $query->withSum(
            ['stockMutations as stock_total' => fn ($mutations) => $mutations->where('created_at', '<=', now())],
            'amount',
        );
    }
}
