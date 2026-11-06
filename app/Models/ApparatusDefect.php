<?php

namespace App\Models;

use App\Support\Fleet\DefectActionStatus;
use App\Support\Media\MemberMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'duplicate_of_defect_id',
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
        'captain5_cleared_at' => 'datetime',
        'owner_set_at' => 'datetime',
        'correction_reported_at' => 'datetime',
        'resolution_cleared_at' => 'datetime',
    ];

    /** Captain 5's queue. Written only by App\Services\Defects\DefectOwnership. */
    public const OWNER_CAPTAIN5 = 'captain5';

    /** Logistics / Support Services (the Admin queues). */
    public const OWNER_LOGISTICS = 'logistics';

    /** Pseudo-compartment of a standalone stretcher report (no inspection; always Captain 5). */
    public const STRETCHER_REPORT_COMPARTMENT = 'stretcher_report';

    /** Issues in the Logistics work queues: everything not owned by Captain 5 (unprojected rows included). */
    public function scopeLogisticsQueue(Builder $query): Builder
    {
        $column = $query->getModel()->qualifyColumn('responsible_owner');

        return $query->where(fn (Builder $owner) => $owner->whereNull($column)->orWhere($column, '<>', self::OWNER_CAPTAIN5));
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolved', false);
    }

    /**
     * `resolved = true` only means "no longer open": it is also true for an issue closed with
     * no action required. Use these two when the question is HOW it ended.
     */
    public function scopeFixed(Builder $query): Builder
    {
        return $query->where('action_status', DefectActionStatus::RESOLVED);
    }

    /** How long a fixed issue stays visible as "resolved" on the Captain 5 workspace and the Admin board. */
    public static function resolvedDisplayHours(): int
    {
        return max(1, (int) config('dashboards.resolved_display_hours', 72));
    }

    /**
     * Fixed issues still on display: resolved inside the retention window and not cleared by staff. Hiding them later
     * never changes the resolution or the history.
     */
    public function scopeRecentlyResolved(Builder $query, ?\DateTimeInterface $now = null): Builder
    {
        $table = $query->getModel()->getTable();
        $now = $now !== null ? \Carbon\CarbonImmutable::instance($now)->utc() : \Carbon\CarbonImmutable::now()->utc();

        return $query->where($table.'.action_status', DefectActionStatus::RESOLVED)
            ->whereNull($table.'.resolution_cleared_at')
            ->where($table.'.resolved_at', '>=', $now->subHours(self::resolvedDisplayHours()));
    }

    /** A merged duplicate is stored as dismissed but was a duplicate report, not a "no action required" call. */
    public function scopeClosedNoAction(Builder $query): Builder
    {
        return $query->where('action_status', DefectActionStatus::DISMISSED)->where(static fn (Builder $kind) => $kind
            ->whereNull('resolution_type')->orWhere('resolution_type', '!=', DefectActionStatus::MERGED_DUPLICATE));
    }

    public function isClosedNoAction(): bool
    {
        return DefectActionStatus::isNoAction($this->action_status) && ! $this->isMergedDuplicate();
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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function firstReporter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'first_reported_by_user_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> who cleared this from the Captain 5 queue */
    public function captain5ClearedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'captain5_cleared_by_user_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function resolvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> who cleared the resolved issue from the lists */
    public function resolutionClearedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'resolution_cleared_by_user_id');
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

    /** The issue this duplicate report was merged into (DefectConsolidationService). */
    public function duplicateOf(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_defect_id');
    }

    public function isMergedDuplicate(): bool
    {
        return DefectActionStatus::isMergedDuplicate($this->resolution_type);
    }

    public function actionLabel(): string
    {
        return DefectActionStatus::labelFor($this->action_status, $this->resolution_type);
    }

    /** The most recent status change in the append-only history (eager-load it for tables). */
    public function latestStatusEvent(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ApparatusDefectEvent::class)
            ->ofMany(['id' => 'max'], static fn ($query) => $query->whereNotNull('to_status')
                // A transition into a status, not a later note that kept it ("action_updated").
                ->where(static fn ($transition) => $transition->whereNull('from_status')->orWhereColumn('from_status', '<>', 'to_status')));
    }

    /**
     * Staff-facing status naming who set it ("Acknowledged by Jane Smith"). Member surfaces keep
     * actionLabel(): they speak for the department, never for an individual staff member.
     */
    public function attributedActionLabel(): string
    {
        if ($this->isMergedDuplicate()) {
            return DefectActionStatus::mergedLabel($this->duplicate_of_defect_id);
        }
        $event = $this->relationLoaded('latestStatusEvent')
            ? $this->getRelation('latestStatusEvent')
            : $this->latestStatusEvent()->with('actor:id,name,display_name')->first();
        $actor = null;
        if ($event instanceof ApparatusDefectEvent && $event->to_status === ($this->action_status ?: DefectActionStatus::REPORTED)) {
            $user = $event->relationLoaded('actor') ? $event->getRelation('actor') : $event->actor()->first(['id', 'name', 'display_name']);
            $actor = \App\Support\Captain5\EventActorLabel::for($event, $user instanceof User ? (string) ($user->display_name ?: $user->name) : null);
        }

        return \App\Support\Fleet\DefectStatusAttribution::label($this->action_status, $actor);
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

        // Private disk first, then a public copy photos:make-private has not moved yet.
        return MemberMedia::exists($path) ? $path : null;
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
