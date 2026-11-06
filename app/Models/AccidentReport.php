<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One accident case. Read and change it through App\Services\Accidents\AccidentReportService, which enforces
 * who may see or complete each part.
 *
 * @property int $id
 * @property string $ulid
 * @property string $reference
 * @property string $report_type
 * @property string $status
 * @property int $created_by_user_id
 * @property int|null $reporter_employee_id
 * @property int|null $subject_employee_id
 * @property int|null $supervisor_employee_id
 * @property int|null $supervisor_user_id
 * @property int|null $unit_master_vehicle_id
 * @property int|null $apparatus_id
 * @property string|null $station_label
 * @property array $driver_data
 * @property array $supervisor_data
 * @property int $revision
 * @property int|null $document_id
 * @property Carbon|null $occurred_at
 * @property Carbon|null $last_autosaved_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $supervisor_completed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $alert_acknowledged_at
 * @property Carbon|null $resolved_at
 * @property bool $follow_up_required
 */
class AccidentReport extends Model
{
    use HasUlids;
    use SoftDeletes;

    public const TYPE_VEHICLE = 'vehicle_accident';

    public const TYPE_SUPERVISOR = 'supervisor_accident';

    public const TYPES = [
        self::TYPE_VEHICLE => 'Vehicle Accident Report',
        self::TYPE_SUPERVISOR => "Supervisor's Report of Accident",
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_AWAITING_SUPERVISOR = 'awaiting_supervisor';

    public const STATUS_COMPLETE = 'complete';

    protected $fillable = [
        'reference', 'report_type', 'status', 'created_by_user_id', 'client_key', 'reporter_employee_id',
        'subject_employee_id', 'supervisor_employee_id', 'supervisor_user_id', 'unit_master_vehicle_id',
        'apparatus_id', 'station_label', 'occurred_at', 'driver_data', 'supervisor_data', 'revision',
        'driver_signature_path', 'driver_signature_sha256', 'supervisor_signature_path', 'supervisor_signature_sha256',
        'last_autosaved_at', 'submitted_at', 'supervisor_completed_at', 'completed_at', 'document_id', 'pdf_revision',
        'alert_acknowledged_at', 'alert_acknowledged_by_user_id', 'follow_up_required', 'follow_up_note',
        'resolved_at', 'resolved_by_user_id', 'resolution_note',
    ];

    protected $hidden = ['driver_signature_path', 'supervisor_signature_path', 'client_key'];

    protected function casts(): array
    {
        return [
            'driver_data' => 'array',
            'supervisor_data' => 'array',
            'revision' => 'integer',
            'occurred_at' => 'datetime',
            'last_autosaved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'supervisor_completed_at' => 'datetime',
            'completed_at' => 'datetime',
            'alert_acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'follow_up_required' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporter_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    /** @return BelongsTo<UnitMasterVehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(UnitMasterVehicle::class, 'unit_master_vehicle_id');
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<PersonnelDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PersonnelDocument::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alert_acknowledged_by_user_id');
    }

    /** @return HasMany<AccidentReportEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AccidentReportEvent::class);
    }

    /**
     * Submitted reports (the driver's part or a supervisor's report): drafts never raise alerts.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at');
    }

    /**
     * Still needs administrative attention: not yet resolved. Opening or acknowledging does not resolve it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->submitted()->whereNull('resolved_at');
    }

    /**
     * Every report a member is part of, in any role.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInvolvingEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('subject_employee_id', $employeeId)
            ->orWhere('reporter_employee_id', $employeeId)
            ->orWhere('supervisor_employee_id', $employeeId));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->report_type] ?? 'Accident report';
    }

    public function statusLabel(): string
    {
        if ($this->resolved_at !== null) {
            return 'Resolved';
        }
        if ($this->follow_up_required) {
            return 'Follow-up required';
        }

        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_AWAITING_SUPERVISOR => 'Driver report submitted · awaiting supervisor',
            self::STATUS_COMPLETE => $this->report_type === self::TYPE_VEHICLE ? 'Report complete' : 'Supervisor completed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
}
