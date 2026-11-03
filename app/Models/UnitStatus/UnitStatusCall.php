<?php

declare(strict_types=1);

namespace App\Models\UnitStatus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Command source call as the Hub last saw it.
 *
 * @property string $source_call_id
 * @property string|null $external_id
 * @property string|null $duplicate_of
 * @property string $call_type
 * @property string $address
 * @property float|null $lat
 * @property float|null $lng
 * @property string $location_quality
 * @property \Carbon\CarbonImmutable|null $received_at
 * @property string $section
 * @property string $source_status
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property \Carbon\CarbonImmutable|null $disappeared_at
 * @property \Carbon\CarbonImmutable $first_seen_at
 * @property \Carbon\CarbonImmutable $last_seen_at
 * @property \Carbon\CarbonImmutable $source_updated_at
 * @property string|null $source_url
 * @property list<array{unitId: string, status: string}>|null $units_json
 * @property \Carbon\CarbonImmutable|null $merged_at
 * @property \Carbon\CarbonImmutable $synced_at
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class UnitStatusCall extends Model
{
    protected $table = 'unit_status_calls';

    protected $primaryKey = 'source_call_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'received_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'disappeared_at' => 'immutable_datetime',
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'source_updated_at' => 'immutable_datetime',
        'merged_at' => 'immutable_datetime',
        'synced_at' => 'immutable_datetime',
        'units_json' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /** @return HasMany<UnitStatusCallUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(UnitStatusCallUnit::class, 'source_call_id', 'source_call_id');
    }
}
