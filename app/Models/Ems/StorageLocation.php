<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageLocation extends Model
{
    protected $table = 'ems_storage_locations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'active' => 'boolean'];
    }

    /** @return HasMany<SupplyItem, $this> */
    public function supplyItems(): HasMany
    {
        return $this->hasMany(SupplyItem::class, 'location_id');
    }
}
