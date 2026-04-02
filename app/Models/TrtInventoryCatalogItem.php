<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrtInventoryCatalogItem extends Model
{
    protected $table = 'trt_inventory_catalog_items';

    protected $fillable = [
        'name',
        'category',
        'expected_quantity',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'expected_quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(TrtInventoryEntry::class, 'catalog_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('category')->orderBy('sort_order');
    }
}
