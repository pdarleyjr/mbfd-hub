<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use LogicException;

class ApparatusInspection extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $inspection): void {
            if ($inspection->isDirty(['actor_user_id', 'apparatus_id', 'vehicle_number', 'designation_at_time', 'operator_name', 'rank', 'shift', 'unit_number', 'employee_id', 'engine_hours', 'miles', 'results', 'checklist_evidence', 'checklist_version', 'client_submission_id', 'submission_payload_hash', 'officer_signature', 'review_flag_explanation', 'review_flag_items', 'inventory_draft_id', 'completed_at'])) {
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
        'review_flag_explanation',
        'review_flag_items',
        'processed_apparatus_id',
        'inventory_draft_id',
        'vehicle_identification',
        'completed_at',
    ];

    protected $casts = [
        'results' => 'array',
        'checklist_evidence' => 'array',
        'pending_effects' => 'array',
        'review_flag_items' => 'array',
        'vehicle_identification' => 'array',
        'engine_hours' => 'decimal:1',
        'miles' => 'integer',
        'completed_at' => 'datetime',
        'test_excluded_at' => 'datetime',
    ];

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->qualifyColumn('test_excluded_at'));
    }

    public function testClassificationEvents(): HasMany
    {
        return $this->hasMany(ApparatusInspectionTestEvent::class)->with('actor')->orderBy('id');
    }

    /** Call inside the operational write transaction, retaining the parent lock until commit. */
    public function assertOperational(): void
    {
        $current = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey(), ['id', 'test_excluded_at']);
        if ($current->test_excluded_at !== null) {
            throw ValidationException::withMessages(['inspection' => 'This inspection is an excluded test. Restore it before adding operational work.']);
        }
    }

    public function displayStatus(): string
    {
        return match ($this->processing_status) {
            'accepted' => 'Accepted',
            'accepted_with_exception' => 'Follow-up needed',
            'pending_vehicle_swap' => 'Held: vehicle swap',
            'held_for_review' => 'Needs approval',
            'rejected' => 'Rejected',
            default => match ($this->review_status) {
                'pending_review' => 'Pending review',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                default => 'Submitted',
            },
        };
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** The physical truck this inspection's findings were applied to (differs from apparatus_id after a decided swap). */
    public function processedApparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class, 'processed_apparatus_id');
    }

    /** @return HasMany<ApparatusIssueMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ApparatusIssueMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasOne<ApparatusVehicleSwapRequest, $this> */
    public function vehicleSwapRequest(): HasOne
    {
        return $this->hasOne(ApparatusVehicleSwapRequest::class);
    }

    public function isHeldForVehicleSwap(): bool
    {
        return $this->processing_status === 'pending_vehicle_swap';
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
