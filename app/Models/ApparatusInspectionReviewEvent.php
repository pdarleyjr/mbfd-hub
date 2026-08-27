<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ApparatusInspectionReviewEvent extends Model
{
    protected $fillable = [
        'apparatus_inspection_id',
        'previous_status',
        'status',
        'internal_note',
        'changed_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Apparatus inspection review events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Apparatus inspection review events are append-only.'));
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
