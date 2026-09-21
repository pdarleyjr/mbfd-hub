<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Redacted view of an Apparatus for the authenticated daily-checkout SPA.
 * Allowlist only: identity + the fields the apparatus tab renders.
 *
 * Never exposes VIN, internal notes, Snipe-IT asset ids, financials, or PM
 * history. Current meters provide context; disputed new observations are
 * retained for reconciliation without replacing authoritative fleet values.
 *
 * @mixin Apparatus
 */
class PublicApparatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $apparatus = $this->resource instanceof Apparatus ? $this->resource : null;
        $dailyCheckoutRequirement = $apparatus?->getAttribute('daily_checkout_requirement');
        $status = $apparatus?->getAttribute('status');

        return [
            'id' => $this->id,
            'name' => $this->designation ?: $this->name ?: $this->unit_id,
            'unit_id' => $this->unit_id,
            'type' => strtolower((string) $this->type),
            'vehicle_number' => $this->vehicle_number,
            'designation' => $this->designation,
            'slug' => $this->slug,
            'status' => $status,
            'daily_checkout_requirement' => $dailyCheckoutRequirement instanceof DailyCheckoutRequirement
                ? $dailyCheckoutRequirement->value
                : DailyCheckoutRequirement::Unknown->value,
            'current_engine_hours' => $this->current_engine_hours,
            'current_miles' => $this->current_miles,
            'meter_baseline_token' => $apparatus ? app(\App\Services\InspectionMeterBaseline::class)->issue($apparatus) : null,
            'pm_health' => $this->last_pm_engine_hours !== null && $this->current_engine_hours !== null
                ? \Illuminate\Support\Arr::only($this->getPmHealthStatus(), ['status', 'hours_since_pm', 'overdue', 'interval_hours'])
                : null,
            'current_defects_count' => $this->when(
                $this->relationLoaded('currentDefects'),
                fn () => $this->currentDefects->count()
            ),
        ];
    }
}
