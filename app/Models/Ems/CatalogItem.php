<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stable identity (slug) shared by bag-form items and supply-room rows only when the extraction said so.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $category
 * @property array<int, string>|null $aliases
 * @property bool $refrigerated
 * @property bool $controlled_substance
 * @property bool|null $has_expiration
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CatalogItem extends Model
{
    protected $table = 'ems_catalog_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'refrigerated' => 'boolean',
            'controlled_substance' => 'boolean',
            'has_expiration' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<SupplyItem, $this> */
    public function supplyItems(): HasMany
    {
        return $this->hasMany(SupplyItem::class, 'catalog_item_id');
    }
}
