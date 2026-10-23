<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Classification history is separate from the member's immutable inspection. */
final class ApparatusInspectionTestEvent extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Inspection test classification events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Inspection test classification events are append-only.'));
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
