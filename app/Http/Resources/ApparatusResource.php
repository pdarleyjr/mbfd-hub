<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON serializer for the Apparatus model.
 *
 * Field set was previously copy-pasted from a different fleet schema and
 * referenced columns that never existed on the apparatuses table (plate,
 * current_mileage, current_hours, fuel_type, capacity, gpm, tank_capacity).
 * Eloquent silently returns null for undefined dynamic properties, which
 * shipped a payload full of permanent nulls to every API consumer.
 *
 * This resource now exposes only columns that exist in the model's
 * `$fillable` definition, plus PM-health metadata derived from the model.
 */
class ApparatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'unit_id'                => $this->unit_id,
            'name'                   => $this->name,
            'type'                   => $this->type,
            'vehicle_number'         => $this->vehicle_number,
            'designation'            => $this->designation,
            'assignment'             => $this->assignment,
            'current_location'       => $this->current_location,
            'class_description'      => $this->class_description,
            'slug'                   => $this->slug,
            'vin'                    => $this->vin,
            'make'                   => $this->make,
            'model'                  => $this->model,
            'year'                   => $this->year,
            'status'                 => $this->status,
            'mileage'                => $this->mileage,
            'current_engine_hours'   => $this->current_engine_hours,
            'current_miles'          => $this->current_miles,
            'last_service_date'      => $this->last_service_date?->toDateString(),
            'last_service_type'      => $this->last_service_type,
            'last_pm_date'           => $this->last_pm_date?->toDateString(),
            'last_pm_mileage'        => $this->last_pm_mileage,
            'last_pm_engine_hours'   => $this->last_pm_engine_hours,
            'pm_interval_miles'      => $this->pm_interval_miles,
            'pm_interval_hours'      => $this->pm_interval_hours,
            'pm_health'              => $this->whenAppended('pm_health', fn () => $this->resource->getPmHealthStatus()),
            'station_id'             => $this->station_id,
            'station'                => $this->whenLoaded('station'),
            'notes'                  => $this->notes,
            'snipeit_asset_id'       => $this->snipeit_asset_id,
            'snipeit_asset_tag'      => $this->snipeit_asset_tag,
            'open_defects_count'     => $this->whenCounted('openDefects'),
            'open_defects'           => $this->whenLoaded('openDefects'),
            'current_defects_count'  => $this->whenCounted('currentDefects'),
            'current_defects'        => $this->whenLoaded('currentDefects'),
            'inspections_count'      => $this->whenCounted('inspections'),
            'reported_at'            => $this->reported_at?->toISOString(),
            'created_at'             => $this->created_at?->toISOString(),
            'updated_at'             => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Conditionally include a value only when the model has the appendable
     * key set (lets callers opt into expensive method calls via append()).
     */
    private function whenAppended(string $key, \Closure $resolver): mixed
    {
        $appended = method_exists($this->resource, 'getAppends')
            ? $this->resource->getAppends()
            : [];

        return in_array($key, $appended, true) ? $resolver() : new \Illuminate\Http\Resources\MissingValue();
    }
}
