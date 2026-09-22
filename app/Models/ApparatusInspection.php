<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ApparatusInspection extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $inspection): void {
            if ($inspection->isDirty(['actor_user_id', 'apparatus_id', 'vehicle_number', 'designation_at_time', 'operator_name', 'rank', 'shift', 'unit_number', 'employee_id', 'engine_hours', 'miles', 'results', 'checklist_evidence', 'checklist_version', 'client_submission_id', 'submission_payload_hash', 'officer_signature', 'completed_at'])) {
                throw new LogicException('Submitted inspection observations are immutable. Record a separate review decision.');
            }
        });
        static::deleting(function (self $inspection): void {
            if ($inspection->reviewEvents()->exists()) {
                throw new LogicException('An apparatus inspection with review history cannot be deleted.');
            }
        });
    }

    protected $fillable = [
        'client_submission_id',
        'submission_payload_hash',
        'checklist_version',
        'apparatus_id',
        'actor_user_id',
        'operator_name',
        'rank',
        'shift',
        'unit_number',
        'engine_hours',
        'miles',
        'vehicle_number',
        'designation_at_time',
        'results',
        'checklist_evidence',
        'pending_effects',
        'officer_signature',
        'employee_id',
        'inspection_reference',
        'review_status',
        'processing_status',
        'completed_at',
    ];

    protected $casts = [
        'results' => 'array',
        'checklist_evidence' => 'array',
        'pending_effects' => 'array',
        'engine_hours' => 'decimal:1',
        'miles' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function displayStatus(): string
    {
        return match ($this->processing_status) {
            'accepted' => 'Accepted',
            'accepted_with_exception' => 'Follow-up needed',
            default => match ($this->review_status) {
                'pending_review' => 'Pending review',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                default => 'Submitted',
            },
        };
    }

    public function apparatus()
    {
        return $this->belongsTo(Apparatus::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function defects(): HasMany
    {
        return $this->hasMany(ApparatusDefect::class);
    }

    /** @return HasMany<ApparatusInspectionReviewEvent, $this> */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ApparatusInspectionReviewEvent::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
