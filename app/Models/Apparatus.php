<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'mileage',
        'last_service_date',
        'notes',
        'station_id',
        'reported_at',
    ];

    protected $casts = [
        'mileage' => 'decimal:2',
        'last_service_date' => 'date',
        'reported_at' => 'datetime',
    ];

    /**
     * Auto-generate slug from designation on create/update if missing.
     */
    protected static function booted(): void
    {
        static::creating(function (Apparatus $apparatus) {
            if (empty($apparatus->slug) && !empty($apparatus->designation)) {
                $apparatus->slug = Str::slug($apparatus->designation);
            }
        });

        static::updating(function (Apparatus $apparatus) {
            if (empty($apparatus->slug) && !empty($apparatus->designation)) {
                $apparatus->slug = Str::slug($apparatus->designation);
            }
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

    public function openDefects()
    {
        return $this->hasMany(ApparatusDefect::class)->where('resolved', false);
    }

    public function currentDefects()
    {
        return $this->openDefects();
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
}
