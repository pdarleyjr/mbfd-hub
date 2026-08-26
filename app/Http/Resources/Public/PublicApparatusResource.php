<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted view of an Apparatus for the unauthenticated daily-checkout
 * SPA. Allowlist only: identity + the fields the apparatus tab renders.
 *
 * Never exposes VIN, internal notes, Snipe-IT asset ids, financials, or PM
 * history. Current meters are intentionally included because the checkout
 * meter form needs the immediately preceding reading to reject regressions.
 */
class PublicApparatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->designation ?: $this->name ?: $this->unit_id,
            'unit_id' => $this->unit_id,
            'type' => strtolower((string) $this->type),
            'vehicle_number' => $this->vehicle_number,
            'designation' => $this->designation,
            'slug' => $this->slug,
            'status' => $this->status,
            'daily_checkout_requirement' => $this->daily_checkout_requirement?->value ?? 'unknown',
            'current_engine_hours' => $this->current_engine_hours,
            'current_miles' => $this->current_miles,
            'current_defects_count' => $this->when(
                $this->relationLoaded('currentDefects'),
                fn () => $this->currentDefects->count()
            ),
        ];
    }
}
