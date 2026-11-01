<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the Captain 5 supply room catalogue. A conversion factor (units_per_package) is
 * only ever set from explicit source text or by an authorized person; null means unknown.
 *
 * @property int $id
 * @property int|null $catalog_item_id
 * @property int|null $location_id
 * @property string|null $source_row_key
 * @property string|null $section_key
 * @property string $name
 * @property string|null $bag_alias_text
 * @property string $base_unit
 * @property string $count_unit
 * @property string|null $package_unit
 * @property int|null $units_per_package
 * @property int|null $packages_per_case
 * @property int|null $threshold_qty_base
 * @property string|null $threshold_source_text
 * @property string|null $qty_per_package_text
 * @property string|null $order_amount_text
 * @property int|null $order_qty_packages
 * @property array<int|string, mixed>|null $suppliers
 * @property array<int|string, mixed>|null $item_numbers
 * @property bool $refrigerated
 * @property bool $controlled_substance
 * @property bool|null $has_expiration
 * @property bool $needs_review
 * @property array<int|string, mixed>|null $review_reasons
 * @property array<string, mixed>|null $source
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SupplyItem extends Model
{
    public const COUNT_UNITS = ['each', 'box', 'case', 'pack', 'bag'];

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
