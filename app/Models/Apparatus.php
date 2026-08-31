<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Apparatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'name',
        'type',
        'vehicle_number',
        'designation',
        'assignment',
        'current_location',
        'class_description',
        'slug',
        'vin',
        'make',
        'model',
        'year',
        'status',
        'daily_checkout_requirement',
        'daily_checkout_template',
        'mileage',
        'last_service_date',
        'notes',
        'station_id',
        'reported_at',
        // PM Maintenance tracking columns
        'current_engine_hours',
        'current_miles',
        'last_pm_date',
        'last_pm_mileage',
        'last_pm_engine_hours',
        'last_service_type',
        'pm_interval_miles',
        'pm_interval_hours',
        // Snipe-IT integration
        'snipeit_asset_id',
        'snipeit_asset_tag',
    ];

    protected $casts = [
        'daily_checkout_requirement' => DailyCheckoutRequirement::class,
        'daily_checkout_template' => DailyCheckoutChecklistTemplate::class,
        'mileage' => 'decimal:2',
        'last_service_date' => 'date',
        'reported_at' => 'datetime',
        // PM Maintenance tracking casts
        'current_engine_hours' => 'decimal:1',
        'current_miles' => 'integer',
        'last_pm_date' => 'date',
        'last_pm_mileage' => 'integer',
        'last_pm_engine_hours' => 'decimal:1',
        'pm_interval_miles' => 'integer',
        'pm_interval_hours' => 'integer',
    ];

    /**
     * Auto-generate slug from designation on create/update if missing.
     */
    protected static function booted(): void
    {
        static::creating(function (Apparatus $apparatus) {
            if (empty($apparatus->slug) && ! empty($apparatus->designation)) {
                $apparatus->slug = Str::slug($apparatus->designation);
            }
        });

        static::updating(function (Apparatus $apparatus) {
            if (empty($apparatus->slug) && ! empty($apparatus->designation)) {
                $apparatus->slug = Str::slug($apparatus->designation);
            }
        });

        static::updated(function (Apparatus $apparatus): void {
            if (! $apparatus->wasChanged('status')) {
                return;
            }

            $previousStatus = $apparatus->getOriginal('status');
            $currentStatus = $apparatus->getAttribute('status');
            if ($previousStatus === $currentStatus) {
                return;
            }

            // The model's persisted updated_at is the authoritative event time.
            // The event is inserted on the same connection/transaction, so an
            // enclosing status-write rollback also rolls this ledger row back.
            $updatedAt = $apparatus->getAttribute('updated_at');
            $changedAt = $updatedAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($updatedAt)->utc()
                : ($updatedAt !== null
                    ? CarbonImmutable::parse((string) $updatedAt, config('app.timezone'))->utc()
                    : now()->utc());

            ApparatusOperationalStatusEvent::query()->create([
                'apparatus_id' => $apparatus->getKey(),
                'previous_status' => $previousStatus,
                'status' => $currentStatus,
                'changed_at' => $changedAt,
            ]);
        });
    }

    /**
     * Get the station that owns this apparatus
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function inspections()
    {
        return $this->hasMany(ApparatusInspection::class);
    }

    public function defects()
    {
        return $this->hasMany(ApparatusDefect::class);
    }

    /** @return HasMany<ApparatusServiceTicket, $this> */
    public function serviceTickets(): HasMany
    {
        return $this->hasMany(ApparatusServiceTicket::class);
    }

    public function openDefects()
    {
        return $this->hasMany(ApparatusDefect::class)->where('resolved', false);
    }

    public function currentDefects()
    {
        return $this->openDefects();
    }

    /** @return HasMany<ApparatusOperationalStatusEvent, $this> */
    public function operationalStatusEvents(): HasMany
    {
        return $this->hasMany(ApparatusOperationalStatusEvent::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    /**
     * Get all inventory allocations for this apparatus
     */
    public function inventoryAllocations()
    {
        return $this->hasMany(ApparatusInventoryAllocation::class, 'apparatus_id');
    }

    /**
     * Get all single gas meters for this apparatus
     */
    public function singleGasMeters()
    {
        return $this->hasMany(SingleGasMeter::class);
    }

    // ============================================
    // PM Maintenance Health Methods
    // ============================================

    /**
     * Calculate aggregate hours since last PM.
     * Returns: current_engine_hours - last_pm_engine_hours
     */
    public function getHoursSinceLastPm(): float
    {
        $current = $this->current_engine_hours ?? 0;
        $lastPm = $this->last_pm_engine_hours ?? 0;

        return max(0, round($current - $lastPm, 1));
    }

    /**
     * Calculate miles since last PM.
     * Returns: current_miles - last_pm_mileage
     */
    public function getMilesSinceLastPm(): int
    {
        $current = $this->current_miles ?? 0;
        $lastPm = $this->last_pm_mileage ?? 0;

        return max(0, $current - $lastPm);
    }

    /**
     * Get PM health status based on aggregate hours.
     *
     * Status Logic:
     *  - GREEN: < 250 hours since last PM
     *  - YELLOW: 250-300 hours (approaching PM window)
     *  - RED: > 300 hours (PM due) or > 305 hours (5+ hours overdue = critical)
     *
     * @return array{status: string, hours: float, miles: int, overdue: bool}
     */
    public function getPmHealthStatus(): array
    {
        $hoursSincePm = $this->getHoursSinceLastPm();
        $milesSincePm = $this->getMilesSinceLastPm();
        $intervalHours = $this->pm_interval_hours ?? 300; // Default 300-hour PM cycle

        // Calculate thresholds based on interval
        $warningThreshold = $intervalHours - 50; // Yellow at 50 hours before PM due
        $criticalThreshold = $intervalHours + 5; // Red critical at 5 hours overdue

        $status = 'green';
        $overdue = false;

        if ($hoursSincePm >= $criticalThreshold) {
            $status = 'red';
            $overdue = true;
        } elseif ($hoursSincePm >= $intervalHours) {
            $status = 'red';
            $overdue = false;
        } elseif ($hoursSincePm >= $warningThreshold) {
            $status = 'yellow';
        }

        return [
            'status' => $status,
            'hours_since_pm' => $hoursSincePm,
            'miles_since_pm' => $milesSincePm,
            'overdue' => $overdue,
            'interval_hours' => $intervalHours,
            'last_pm_date' => $this->last_pm_date?->format('Y-m-d'),
        ];
    }

    /**
     * Check if PM is due (hours >= interval).
     */
    public function isPmDue(): bool
    {
        return $this->getHoursSinceLastPm() >= ($this->pm_interval_hours ?? 300);
    }

    /**
     * Check if PM is critically overdue (5+ hours past interval).
     */
    public function isPmCritical(): bool
    {
        return $this->getHoursSinceLastPm() >= (($this->pm_interval_hours ?? 300) + 5);
    }

    public function isDailyCheckoutRequired(): bool
    {
        return $this->daily_checkout_requirement === DailyCheckoutRequirement::Required;
    }
}
