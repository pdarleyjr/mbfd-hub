<?php

declare(strict_types=1);

namespace App\Models\DailyRoster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class RosterSection extends Model
{
    public const ROLE_COMMAND_300 = 'command_300';

    public const ROLE_CAPTAIN_5 = 'captain_5';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Roster sections are immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Roster sections are immutable.'));
    }

    /** @return BelongsTo<RosterSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RosterSnapshot::class, 'roster_snapshot_id');
    }

    /** @return HasMany<RosterAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class)->orderBy('position');
    }
}
