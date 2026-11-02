<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Apparatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A Medical Inventory Request (MIR). The submission facts and the snapshot are written once and
 * never change; only the workflow columns (status, review, close, cancel, Files copy id) move.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property string $status
 * @property int|null $submitted_by_user_id
 * @property int|null $submitted_by_employee_id
 * @property string $submitter_name
 * @property string|null $submitter_rank
 * @property \Illuminate\Support\Carbon $submitted_at
 * @property string $target_kind
 * @property int|null $apparatus_id
 * @property string $target_label
 * @property string|null $event_assignment
 * @property string|null $member_note
 * @property string $client_submission_id
 * @property array<string, mixed> $source_form_versions
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_sha256
 * @property string|null $operational_form_record_id
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property int|null $reviewed_by_user_id
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property int|null $closed_by_user_id
 * @property string|null $close_reason
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $reviewed_by_name
 * @property int|null $revision_of_request_id
 * @property int $revision_number
 * @property \Illuminate\Support\Carbon|null $returned_at
 * @property int|null $returned_by_user_id
 * @property string|null $returned_by_name
 * @property string|null $return_reason
 * @property \Illuminate\Support\Carbon|null $reconciled_at
 * @property int|null $reconciled_by_user_id
 * @property string|null $reconciled_by_name
 * @property string|null $reconciliation_batch
 * @property-read int|null $lines_count
 * @property-read int|string|null $qty_requested
 * @property-read int|string|null $qty_issued
 * @property-read string|null $last_message_context
 */
class InventoryRequest extends Model
{
    public const SUBMITTED = 'submitted';

    public const REVIEWED = 'reviewed';

    public const PREPARED = 'prepared';

    public const PARTIALLY_ISSUED = 'partially_issued';

    public const ISSUED = 'issued';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    /** Rejected by Captain 5, waiting for the member to correct and resend it. */
    public const RETURNED = 'returned';

    /** Deducted from the supply room and archived. */
    public const RECONCILED = 'reconciled';

    /** The member sent a corrected revision; that revision is now the live record. */
    public const SUPERSEDED = 'superseded';

    public const OPEN_STATUSES = [self::SUBMITTED, self::REVIEWED, self::PREPARED, self::PARTIALLY_ISSUED, self::ISSUED];

    public const STATUSES = [...self::OPEN_STATUSES, self::CLOSED, self::CANCELLED, self::RETURNED, self::RECONCILED, self::SUPERSEDED];

    /** What Captain 5 still has in front of them on Submitted Inventory Forms. */
    public const ACTIVE_STATUSES = [self::SUBMITTED, self::REVIEWED, self::PREPARED, self::RETURNED];

    /** Finished records, including anything the older Issue workflow already handled. */
    public const ARCHIVED_STATUSES = [self::RECONCILED, self::SUPERSEDED, self::ISSUED, self::PARTIALLY_ISSUED, self::CLOSED, self::CANCELLED];

    /** Statuses a form can be acknowledged, rejected or reconciled from. */
    public const WORKABLE_STATUSES = [self::SUBMITTED, self::REVIEWED, self::PREPARED];

    public const TARGET_RESCUE = 'rescue_unit';

    public const TARGET_OTHER = 'other_unit';

    public const TARGET_DETAIL = 'detail_event';

    public const TARGET_KINDS = [self::TARGET_RESCUE, self::TARGET_OTHER, self::TARGET_DETAIL];

    /** Columns that can never change after the request is created. */
    private const FROZEN = [
        'uuid', 'number', 'submitted_by_user_id', 'submitted_by_employee_id', 'submitter_name', 'submitter_rank',
        'submitted_at', 'target_kind', 'apparatus_id', 'target_label', 'event_assignment', 'member_note',
        'client_submission_id', 'source_form_versions', 'snapshot', 'snapshot_sha256', 'revision_of_request_id', 'revision_number',
    ];

    protected $table = 'ems_inventory_requests';

    protected $fillable = [
        'uuid', 'number', 'status', 'submitted_by_user_id', 'submitted_by_employee_id', 'submitter_name', 'submitter_rank', 'submitted_at',
        'target_kind', 'apparatus_id', 'target_label', 'event_assignment', 'member_note', 'client_submission_id', 'source_form_versions',
        'snapshot', 'snapshot_sha256', 'operational_form_record_id', 'reviewed_at', 'reviewed_by_user_id', 'closed_at', 'closed_by_user_id',
        'close_reason', 'cancelled_at', 'cancelled_by_user_id', 'cancel_reason', 'last_activity_at', 'reviewed_by_name', 'revision_of_request_id',
        'revision_number', 'returned_at', 'returned_by_user_id', 'returned_by_name', 'return_reason', 'reconciled_at', 'reconciled_by_user_id',
        'reconciled_by_name', 'reconciliation_batch',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $request): void {
            foreach (self::FROZEN as $column) {
                if ($request->isDirty($column)) {
                    throw new LogicException("A submitted request's {$column} cannot be changed.");
                }
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Medical inventory requests are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'source_form_versions' => 'array',
            'snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'revision_number' => 'integer',
            'returned_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isAcknowledged(): bool
    {
        return $this->reviewed_at !== null;
    }

    /** Whether the older Issue workflow already moved any of this request. Such a record is never reconciled. */
    public function hasIssuance(): bool
    {
        return $this->lines()->where('issued_qty', '>', 0)->exists();
    }

    public function canBeWorked(): bool
    {
        return in_array($this->status, self::WORKABLE_STATUSES, true) && ! $this->hasIssuance();
    }

    public function canBeReconciled(): bool
    {
        return $this->isAcknowledged() && $this->canBeWorked();
    }

    /** @return BelongsTo<self, $this> */
    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_request_id');
    }

    /** @return HasOne<self, $this> */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'revision_of_request_id');
    }

    /** @return HasMany<InventoryRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryRequestLine::class, 'request_id')->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<InventoryRequestEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(InventoryRequestEvent::class, 'request_id')->orderBy('id');
    }

    /** @return HasMany<InventoryRequestMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(InventoryRequestMessage::class, 'request_id')->orderBy('id');
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
