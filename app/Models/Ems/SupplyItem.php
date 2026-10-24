<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the Captain 5 supply room catalogue. A conversion factor (units_per_package) is
 * only ever set from explicit source text or by an authorized person; null means unknown.
 */
class SupplyItem extends Model
{
    protected $table = 'ems_supply_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'units_per_package' => 'integer',
            'packages_per_case' => 'integer',
            'threshold_qty_base' => 'integer',
            'order_qty_packages' => 'integer',
            'suppliers' => 'array',
            'item_numbers' => 'array',
            'refrigerated' => 'boolean',
            'controlled_substance' => 'boolean',
            'has_expiration' => 'boolean',
            'needs_review' => 'boolean',
            'review_reasons' => 'array',
            'source' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'location_id');
    }

    /** @return HasMany<SupplyItemEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SupplyItemEvent::class, 'supply_item_id')->orderByDesc('id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'supply_item_id');
    }

    /** Controlled substances are never part of supply-room stock. */
    public function tracksStock(): bool
    {
        return $this->active && ! $this->controlled_substance;
    }
}
