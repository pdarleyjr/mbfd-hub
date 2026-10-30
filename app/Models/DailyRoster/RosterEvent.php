<?php

declare(strict_types=1);

namespace App\Models\DailyRoster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history of imports and pointer moves. Context holds codes, never names. */
final class RosterEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'duty_date' => 'immutable_date',
        'context' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Roster events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Roster events are append-only.'));
    }

    /** @return BelongsTo<RosterImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(RosterImport::class, 'roster_import_id');
    }
}
