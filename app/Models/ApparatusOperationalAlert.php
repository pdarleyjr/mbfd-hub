<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One authoritative operational alert about a unit. Station pages, the apparatus profile and Fleet all read this
 * row; none of them keeps a copy. apparatus_id and vehicle_number are the truck when the alert was raised and do
 * not change; unit_slug follows the unit when it moves between truck rows.
 *
 * @property int $id
 * @property int $apparatus_id
 * @property string $unit_slug
 * @property string $unit_designation
 * @property string|null $vehicle_number
 * @property int|null $station_id
 * @property array<int, int>|null $additional_station_ids
 * @property string $category
 * @property string $title
 * @property string $message
 * @property string|null $reason
 * @property string|null $instructions
 * @property CarbonImmutable|null $scheduled_date
 * @property string|null $scheduled_window
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $ends_on
 * @property string $status
 * @property int|null $planned_replacement_apparatus_id
 * @property int|null $apparatus_service_ticket_id
 * @property int|null $completed_by_changeout_id
 * @property string|null $source
 * @property string|null $dedupe_key
 * @property bool $send_notification
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $close_note
 */
class ApparatusOperationalAlert extends Model
{
    public const CATEGORY_UPCOMING_SWAP = 'upcoming_swap';

    public const CATEGORY_MAINTENANCE = 'maintenance';

    public const CATEGORY_RECALL = 'recall';

    public const CATEGORY_SERVICE_INTERRUPTION = 'service_interruption';

    public const CATEGORY_GENERAL = 'general';

    public const CATEGORIES = [
        self::CATEGORY_UPCOMING_SWAP => 'Upcoming vehicle change',
        self::CATEGORY_MAINTENANCE => 'Maintenance',
        self::CATEGORY_RECALL => 'Recall',
        self::CATEGORY_SERVICE_INTERRUPTION => 'Service interruption',
        self::CATEGORY_GENERAL => 'Operational notice',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CLOSED = 'closed';

    public const WINDOWS = ['morning', 'afternoon', 'evening'];

    protected $fillable = [
        'apparatus_id',
        'unit_slug',
        'unit_designation',
        'vehicle_number',
        'station_id',
        'additional_station_ids',
        'category',
        'title',
        'message',
        'reason',
        'instructions',
        'scheduled_date',
        'scheduled_window',
        'scheduled_at',
        'ends_on',
        'status',
        'planned_replacement_apparatus_id',
        'apparatus_service_ticket_id',
        'completed_by_changeout_id',
        'source',
        'dedupe_key',
        'send_notification',
        'notified_at',
        'created_by_user_id',
        'updated_by_user_id',
        'closed_by_user_id',
        'closed_at',
        'close_note',
    ];

    protected function casts(): array
    {
        return [
            'additional_station_ids' => 'array',
            'scheduled_date' => 'immutable_date',
            'scheduled_at' => 'immutable_datetime',
            'ends_on' => 'immutable_date',
            'send_notification' => 'boolean',
            'notified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isUpcomingSwap(): bool
    {
        return $this->category === self::CATEGORY_UPCOMING_SWAP;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? str($this->category)->headline()->toString();
    }

    /** "Sunday, November 1, afternoon", in Eastern time. A date with no time never gains one here. */
    public function scheduleLabel(): ?string
    {
        if ($this->scheduled_at !== null) {
            return $this->scheduled_at->setTimezone(HubTimezone::NAME)->format('l, F j, g:i A');
        }
        if ($this->scheduled_date === null) {
            return null;
        }
        $label = $this->scheduled_date->format('l, F j');

        return $this->scheduled_window !== null ? $label.', '.$this->scheduled_window : $label;
    }

    public function datePassed(): bool
    {
        if ($this->scheduled_at !== null) {
            return $this->scheduled_at->isPast();
        }

        return $this->scheduled_date !== null
            && $this->scheduled_date->format('Y-m-d') < HubTimezone::today()->format('Y-m-d');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** @param Builder<self> $query */
    public function scopeForUnit(Builder $query, string $unitSlug): Builder
    {
        return $query->where('unit_slug', $unitSlug);
    }

    /** @return BelongsTo<Apparatus, $this> the truck when the alert was raised */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function plannedReplacement(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class, 'planned_replacement_apparatus_id');
    }

    /** @return BelongsTo<ApparatusServiceTicket, $this> */
    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ApparatusServiceTicket::class, 'apparatus_service_ticket_id');
    }

    /** @return BelongsTo<ApparatusChangeout, $this> */
    public function completedByChangeout(): BelongsTo
    {
        return $this->belongsTo(ApparatusChangeout::class, 'completed_by_changeout_id');
    }

    /** @return HasMany<ApparatusChangeout, $this> */
    public function changeouts(): HasMany
    {
        return $this->hasMany(ApparatusChangeout::class, 'alert_id');
    }

    /** @return HasMany<ApparatusFleetEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApparatusFleetEvent::class, 'alert_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
