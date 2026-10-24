<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Apparatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const OPEN_STATUSES = [self::SUBMITTED, self::REVIEWED, self::PREPARED, self::PARTIALLY_ISSUED, self::ISSUED];

    public const STATUSES = [...self::OPEN_STATUSES, self::CLOSED, self::CANCELLED];

    public const TARGET_RESCUE = 'rescue_unit';

    public const TARGET_OTHER = 'other_unit';

    public const TARGET_DETAIL = 'detail_event';

    public const TARGET_KINDS = [self::TARGET_RESCUE, self::TARGET_OTHER, self::TARGET_DETAIL];

    /** Columns that can never change after the request is created. */
    private const FROZEN = [
        'uuid', 'number', 'submitted_by_user_id', 'submitted_by_employee_id', 'submitter_name', 'submitter_rank',
        'submitted_at', 'target_kind', 'apparatus_id', 'target_label', 'event_assignment', 'member_note',
        'client_submission_id', 'source_form_versions', 'snapshot', 'snapshot_sha256',
    ];

    protected $table = 'ems_inventory_requests';

    protected $fillable = [
        'uuid', 'number', 'status', 'submitted_by_user_id', 'submitted_by_employee_id', 'submitter_name', 'submitter_rank', 'submitted_at',
        'target_kind', 'apparatus_id', 'target_label', 'event_assignment', 'member_note', 'client_submission_id', 'source_form_versions',
        'snapshot', 'snapshot_sha256', 'operational_form_record_id', 'reviewed_at', 'reviewed_by_user_id', 'closed_at', 'closed_by_user_id',
        'close_reason', 'cancelled_at', 'cancelled_by_user_id', 'cancel_reason', 'last_activity_at',
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
