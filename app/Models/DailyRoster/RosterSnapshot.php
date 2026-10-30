<?php

declare(strict_types=1);

namespace App\Models\DailyRoster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** One accepted export for a duty date. Immutable; only the active pointer moves. */
final class RosterSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'duty_date' => 'immutable_date',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'source_generated_at' => 'immutable_datetime',
        'warnings' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Roster snapshots are immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Roster snapshots are immutable.'));
    }

    /** @return BelongsTo<RosterImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(RosterImport::class, 'roster_import_id');
    }

    /** @return HasMany<RosterSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(RosterSection::class)->orderBy('position');
    }

    /** @return HasMany<RosterAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }
}
