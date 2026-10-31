<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One move of a unit from truck A to truck B, with the equipment handoff that went with it. The record stays
 * after the move and is never rewritten by a later move: a return points back at this row through
 * return_of_changeout_id.
 *
 * @property int $id
 * @property string $client_submission_id
 * @property string $payload_hash
 * @property int|null $alert_id
 * @property int|null $return_of_changeout_id
 * @property string $unit_slug
 * @property string $unit_designation
 * @property int|null $station_id
 * @property int $from_apparatus_id
 * @property int|null $to_apparatus_id
 * @property string|null $from_vehicle_number
 * @property string $to_vehicle_number
 * @property bool $to_vehicle_listed
 * @property string $status
 * @property string $handoff_status
 * @property string|null $checklist_version
 * @property string|null $note
 * @property int|null $submitted_by_user_id
 * @property string $submitted_by_name
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $effective_at
 * @property array<string, mixed>|null $move_audit
 */
class ApparatusChangeout extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING_FLEET = 'pending_fleet';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const HANDOFF_COMPLETE = 'complete';

    public const HANDOFF_PENDING_VERIFICATION = 'pending_verification';

    protected $fillable = [
        'client_submission_id',
        'payload_hash',
        'alert_id',
        'return_of_changeout_id',
        'unit_slug',
        'unit_designation',
        'station_id',
        'from_apparatus_id',
        'to_apparatus_id',
        'from_vehicle_number',
        'to_vehicle_number',
        'to_vehicle_listed',
        'status',
        'handoff_status',
        'checklist_version',
        'note',
        'submitted_by_user_id',
        'submitted_by_name',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'effective_at',
        'move_audit',
    ];

    protected function casts(): array
    {
        return [
            'to_vehicle_listed' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'effective_at' => 'immutable_datetime',
            'move_audit' => 'array',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPendingFleet(): bool
    {
        return $this->status === self::STATUS_PENDING_FLEET;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_COMPLETED => 'Complete',
            self::STATUS_PENDING_FLEET => 'Waiting on Fleet',
            self::STATUS_REJECTED => 'Not approved',
            self::STATUS_CANCELLED => 'Cancelled',
            default => str($status)->headline()->toString(),
        };
    }

    /** @return BelongsTo<ApparatusOperationalAlert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(ApparatusOperationalAlert::class, 'alert_id');
    }

    /** @return BelongsTo<self, $this> the earlier changeout this one reverses */
    public function returnOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_of_changeout_id');
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function fromApparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class, 'from_apparatus_id');
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function toApparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class, 'to_apparatus_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return HasMany<ApparatusChangeoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ApparatusChangeoutItem::class);
    }

    /** @return HasMany<ApparatusFleetEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApparatusFleetEvent::class, 'changeout_id')->orderBy('id');
    }
}
