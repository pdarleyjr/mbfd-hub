<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One move of a unit from truck A to truck B, with what the crew took and what they left. It happened when it was sent:
 * nobody approves it, and Fleet acknowledges having seen it. The record stays after the move and is never rewritten by
 * a later move: a return points back at this row through return_of_changeout_id.
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
 * @property CarbonImmutable|null $effective_at
 * @property array<string, mixed>|null $move_audit
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $acknowledged_by_user_id
 */
class ApparatusChangeout extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const HANDOFF_COMPLETE = 'complete';

    /** The old truck after every swap, whatever it was before. */
    public const OLD_TRUCK_STATUS = 'Out of Service';

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
        'effective_at',
        'move_audit',
        'acknowledged_at',
        'acknowledged_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'to_vehicle_listed' => 'boolean',
            'effective_at' => 'immutable_datetime',
            'move_audit' => 'array',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** "R 44 changed trucks: 17503 to 1033", as every alert about this change reads. */
    public function headline(): string
    {
        return $this->unit_designation.' changed trucks: '.($this->from_vehicle_number ?: 'not recorded').' to '.$this->to_vehicle_number;
    }

    /** @return list<string> what Fleet should look at: a traded unit, a new record, a number two records share */
    public function reviewNotes(): array
    {
        return array_values(array_filter((array) ($this->move_audit['review_notes'] ?? []), 'is_string'));
    }

    /** @param Builder<self> $query */
    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED)->whereNull('acknowledged_at');
    }

    /** @param Builder<self> $query */
    public function scopeInvolvingTruck(Builder $query, int $truckId): Builder
    {
        return $query->where(static fn (Builder $inner): Builder => $inner->where('from_apparatus_id', $truckId)->orWhere('to_apparatus_id', $truckId));
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

    /** @return BelongsTo<Apparatus, $this> the truck the unit moved onto: the row dashboards group this change under */
    public function apparatus(): BelongsTo
    {
        return $this->toApparatus();
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    /** @return HasMany<ApparatusChangeoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ApparatusChangeoutItem::class);
    }

    /** @return HasMany<ApparatusChangeoutItem, $this> */
    public function leftBehindItems(): HasMany
    {
        return $this->items()->where('custody_status', ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND)->orderBy('id');
    }

    /** @return HasMany<ApparatusFleetEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApparatusFleetEvent::class, 'changeout_id')->orderBy('id');
    }
}
