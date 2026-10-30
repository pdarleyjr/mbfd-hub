<?php

declare(strict_types=1);

namespace App\Models\DailyRoster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The single atomic pointer from a duty date to the snapshot members see. */
final class RosterActiveVersion extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'duty_date';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'effective_generated_at' => 'immutable_datetime',
        'last_confirmed_at' => 'immutable_datetime',
        'pinned_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<RosterSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RosterSnapshot::class, 'roster_snapshot_id');
    }
}
