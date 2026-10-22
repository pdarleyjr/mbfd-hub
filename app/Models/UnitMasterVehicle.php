<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read int|null $pending_changes_count
 * @property-read int|null $visible_documents_count
 */
class UnitMasterVehicle extends Model
{
    use HasFactory;

    public const CATEGORY_VEHICLE = 'vehicle';

    public const CATEGORY_LABELS = [
        'vehicle' => 'Vehicle',
        'trailer' => 'Trailer',
        'boat' => 'Boat',
        'boat_motor' => 'Boat motor',
        'utility_cart' => 'Utility cart',
    ];

    /** Human labels for master fields, used by audit history and exports. */
    public const FIELD_LABELS = [
        'veh_number' => 'Vehicle #',
        'make' => 'Make',
        'model' => 'Model',
        'year' => 'Year',
        'color' => 'Color',
        'tag_number' => 'License plate / tag',
        'serial_number' => 'VIN / serial',
        'dept_code' => 'Department',
        'employee_or_vehicle_name' => 'Assigned name / vehicle name',
        'assignment' => 'Assignment (driver)',
        'current_driver_employee_id' => 'Current driver',
        'section' => 'Section',
        'location' => 'Location',
        'fleet_status_code' => 'FY27 status code',
        'fleet_class_code' => 'Fleet class',
        'fleet_class_description' => 'Class description',
        'asset_category' => 'Category',
        'record' => 'Record',
    ];

    protected $table = 'unit_master_vehicles';

    protected $fillable = [
        'veh_number',
        'make',
        'model',
        'year',
        'color',
        'tag_number',
        'dept_code',
        'employee_or_vehicle_name',
        'sunpass_number',
        'als_license',
        'serial_number',
        'section',
        'assignment',
        'current_driver_employee_id',
        'location',
        'fleet_status_code',
        'fleet_class_code',
        'fleet_class_description',
        'asset_category',
        'needs_fleet_review',
        'record_source',
    ];

    protected $casts = [
        'year' => 'string',
        'needs_fleet_review' => 'boolean',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'current_driver_employee_id');
    }

    /** @return HasMany<UnitMasterVehicleChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(UnitMasterVehicleChange::class)->latest('id');
    }

    /** @return BelongsToMany<VehicleDocument, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(VehicleDocument::class, 'vehicle_document_unit_master_vehicle', 'vehicle_id', 'document_id')
            ->withTimestamps();
    }

    /** @return HasMany<TakeHomeVehicleInspection, $this> */
    public function takeHomeInspections(): HasMany
    {
        return $this->hasMany(TakeHomeVehicleInspection::class)->latest('submitted_at');
    }

    /** @return HasOne<TakeHomeVehicleInspection, $this> */
    public function latestTakeHomeInspection(): HasOne
    {
        return $this->hasOne(TakeHomeVehicleInspection::class)->latestOfMany('submitted_at');
    }

    /** Derive the asset category from the city fleet class description. */
    public static function categoryFromClassDescription(?string $description): string
    {
        $text = strtoupper((string) $description);

        return match (true) {
            str_contains($text, 'BOAT MOTOR') => 'boat_motor',
            str_contains($text, 'TRAILER') => 'trailer',
            str_contains($text, 'BOAT') => 'boat',
            str_contains($text, 'UTILITY CART') => 'utility_cart',
            default => self::CATEGORY_VEHICLE,
        };
    }

    public function category(): string
    {
        return $this->asset_category ?: self::CATEGORY_VEHICLE;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category()] ?? 'Vehicle';
    }

    /** Trailers, boats, motors and carts are kept on the roster but are not road vehicles. */
    public function isNonroad(): bool
    {
        return $this->category() !== self::CATEGORY_VEHICLE;
    }

    /** Model year age, derived instead of stored so it never goes stale. */
    public function ageInYears(): ?int
    {
        $year = (int) preg_replace('/\D/', '', (string) $this->year);

        return $year >= 1950 ? max(0, (int) now('America/New_York')->year - $year) : null;
    }

    public function displayName(): string
    {
        return trim(implode(' ', array_filter([
            $this->veh_number ? '#'.$this->veh_number : null,
            $this->year,
            $this->make,
            $this->model,
        ])));
    }
}
