<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history of one equipment issue. Never edited, never deleted. */
final class ApparatusDefectEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['details' => 'array'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Defect events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Defect events are append-only.'));
    }

    /** @return BelongsTo<ApparatusDefect, $this> */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
