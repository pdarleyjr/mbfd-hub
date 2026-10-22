<?php

namespace App\Models;

use App\Support\Fleet\DefectActionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ApparatusDefect extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $defect): void {
            $actionChanged = $defect->isDirty('action_status');
            $statusChanged = $defect->isDirty('status');
            $resolvedChanged = $defect->isDirty('resolved');

            if (! $actionChanged && ! $statusChanged && ! $resolvedChanged) {
                return;
            }

            if ($actionChanged) {
                // action_status is the lifecycle source of truth; legacy columns derive from it.
                $action = (string) $defect->action_status;
                $defect->resolved = DefectActionStatus::isClosed($action);
                $defect->status = DefectActionStatus::legacyStatus($action);
            } else {
                // Legacy writers set status/resolved directly; keep action_status consistent.
                if ($statusChanged) {
                    $defect->resolved = $defect->status === 'resolved';
                } else {
                    $defect->status = $defect->resolved ? 'resolved' : 'open';
                }
                $current = (string) ($defect->getAttribute('action_status') ?? DefectActionStatus::REPORTED);
                if ($defect->resolved && ! DefectActionStatus::isClosed($current)) {
                    $defect->action_status = DefectActionStatus::RESOLVED;
                } elseif (! $defect->resolved && DefectActionStatus::isClosed($current)) {
                    $defect->action_status = $defect->status === 'in_progress'
                        ? DefectActionStatus::ACTION_PENDING
                        : DefectActionStatus::REPORTED;
                } elseif (! $defect->resolved && $current === DefectActionStatus::REPORTED && $defect->status === 'in_progress') {
                    $defect->action_status = DefectActionStatus::ACTION_PENDING;
                }
            }

            if ($defect->resolved) {
                $defect->resolved_at ??= now();
            } else {
                $defect->resolved_at = null;
            }
        });
    }

    protected $fillable = [
        'apparatus_id',
        'apparatus_inspection_id',
        'compartment',
        'item',
        'status',
        'issue_type',
        'reported_date',
        'notes',
        'photo', // base64 encoded image
        'photo_path',
        'resolved',
        'resolved_at',
        'resolution_notes',
        'defect_history',
        'operational_impact',
        'service_ticket_id',
        'compartment_key',
        'item_key',
        'severity',
        'action_status',
        'action_details',
        'first_reported_inspection_id',
        'first_reported_by_user_id',
        'first_reported_at',
        'last_observed_inspection_id',
        'last_observed_at',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'resolved_by_user_id',
        'resolution_type',
        'recurrence_of_defect_id',
    ];

    protected $casts = [
        'defect_history' => 'array',
        'resolved' => 'boolean',
        'reported_date' => 'date',
        'action_details' => 'array',
        'resolved_at' => 'datetime',
        'first_reported_at' => 'datetime',
        'last_observed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolved', false);
    }

    public function scopeMissing(Builder $query): Builder
    {
        return $query->where('issue_type', 'missing');
    }

    public function scopeDamaged(Builder $query): Builder
    {
        return $query->where('issue_type', 'damaged');
    }

    public function scopeCriticalForReadiness(Builder $query): Builder
    {
        return $this->scopeUnresolved($query)->whereIn('issue_type', ['missing', 'damaged']);
    }

    public function apparatus()
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<ApparatusDefectObservation, $this> */
    public function latestObservation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ApparatusDefectObservation::class)->latestOfMany();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ApparatusDefectObservation, $this> */
    public function observations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApparatusDefectObservation::class)->orderBy('id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ApparatusDefectEvent, $this> */
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApparatusDefectEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ApparatusIssueMessage, $this> */
    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApparatusIssueMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ApparatusDefectParticipant, $this> */
    public function participants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApparatusDefectParticipant::class);
    }

    public function firstReportedInspection(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'first_reported_inspection_id');
    }

    public function lastObservedInspection(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'last_observed_inspection_id');
    }

    /** The earlier, verified-resolved episode of this same item, when this is a recurrence. */
    public function recurrenceOf(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_of_defect_id');
    }

    public function actionLabel(): string
    {
        return DefectActionStatus::label($this->action_status);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ApparatusServiceTicket, $this> */
    public function serviceTicket(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ApparatusServiceTicket::class, 'service_ticket_id');
    }

    /**
     * Get the inspection this defect was reported in
     */
    public function inspection()
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /**
     * Get recommendations for this defect
     */
    public function recommendations()
    {
        return $this->hasMany(ApparatusDefectRecommendation::class, 'apparatus_defect_id');
    }

    /**
     * Get allocations for this defect
     */
    public function allocations()
    {
        return $this->hasMany(ApparatusInventoryAllocation::class, 'apparatus_defect_id');
    }

    public function availablePhotoPath(): ?string
    {
        $path = $this->getRawOriginal('photo_path');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return Storage::disk('public')->exists($path) ? $path : null;
    }

    public function hasMissingPhotoReference(): bool
    {
        $path = $this->getRawOriginal('photo_path');

        return is_string($path) && trim($path) !== '' && $this->availablePhotoPath() === null;
    }

    /**
     * Legacy entry point (e.g. the legacy approval replay). Delegates to the continuity
     * service so the origin report, notes, photo and workflow status are never overwritten.
     */
    public static function recordDefect(
        $apparatusId,
        $compartment,
        $item,
        $status,
        $notes,
        $photoPath = null,
        $inspectionId = null,
    ) {
        return app(\App\Services\Defects\DefectContinuityService::class)->recordLegacyFinding(
            (int) $apparatusId,
            (string) $compartment,
            (string) $item,
            (string) $status,
            $notes,
            $photoPath,
            $inspectionId !== null ? (int) $inspectionId : null,
        );
    }
}
