<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only operational-status timeline emitted by the Apparatus Eloquent
 * model after a real status transition. It intentionally records no actor:
 * the generic model writer cannot truthfully attribute CLI, job, admin, and
 * workflow writes to one identity model.
 */
final class ApparatusOperationalStatusEvent extends Model
{
    protected $fillable = [
        'apparatus_id',
        'previous_status',
        'status',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Apparatus operational status events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Apparatus operational status events are append-only.'));
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }
}
