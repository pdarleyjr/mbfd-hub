<?php

declare(strict_types=1);

namespace App\Models\UnitStatus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of our units on a call.
 *
 * @property string $source_call_id
 * @property string $unit_code
 * @property string $designation
 * @property string $status
 * @property bool $confirmed
 * @property \Carbon\CarbonImmutable $first_seen_on_call_at
 * @property \Carbon\CarbonImmutable $status_observed_at
 */
final class UnitStatusCallUnit extends Model
{
    protected $table = 'unit_status_call_units';

    protected $guarded = ['id'];

    protected $casts = [
        'confirmed' => 'boolean',
        'first_seen_on_call_at' => 'immutable_datetime',
        'status_observed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<UnitStatusCall, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(UnitStatusCall::class, 'source_call_id', 'source_call_id');
    }
}
