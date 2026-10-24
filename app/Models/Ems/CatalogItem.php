<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stable identity (slug) shared by bag-form items and supply-room rows only when the extraction said so. */
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
