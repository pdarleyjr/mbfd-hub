<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $label
 * @property int $sort
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read int|null $tracked_count Active supply items that count toward stock (added by withCount)
 * @property-read int|null $item_count Active supply items (added by withCount)
 */
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
