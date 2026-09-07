<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StationInspection extends Model
{
    protected $fillable = [
        'client_submission_id',
        'station_id',
        'inspector_id',
        'inspection_date',
        'inspection_type',
        'form_data',
        'overall_status',
        'review_status',
        'inspector_signature',
        'reviewer_signature',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'notes',
        'sog_mandate_acknowledged',
        'extinguishing_system_date',
    ];

    protected $casts = [
        'form_data' => 'array',
        'inspection_date' => 'date',
        'reviewed_at' => 'datetime',
        'sog_mandate_acknowledged' => 'boolean',
        'extinguishing_system_date' => 'date',
    ];

    /** @var list<string> */
    private const SUBMITTED_EVIDENCE = [
        'client_submission_id',
        'station_id',
        'inspector_id',
        'inspection_date',
        'inspection_type',
        'form_data',
        'overall_status',
        'inspector_signature',
        'notes',
        'sog_mandate_acknowledged',
        'extinguishing_system_date',
    ];

    protected static function booted(): void
    {
        static::updating(function (StationInspection $inspection): void {
            foreach (self::SUBMITTED_EVIDENCE as $attribute) {
                if ($inspection->isDirty($attribute)) {
                    throw new LogicException('Submitted station inspection evidence is immutable.');
                }
            }

            if ($inspection->getOriginal('review_status') !== 'pending_review'
                && $inspection->isDirty(['review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'reviewer_signature'])) {
                throw new LogicException('A station inspection review decision is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Submitted station inspection evidence cannot be deleted.');
        });
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
