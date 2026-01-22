<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Apparatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'vin',
        'make',
        'model',
        'year',
        'status',
        'mileage',
        'last_service_date',
        'notes',
    ];

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
        return $this->defects()->where('resolved', false);
    }

    /**
     * Get all inventory allocations for this apparatus
     */
    public function inventoryAllocations()
    {
        return $this->hasMany(ApparatusInventoryAllocation::class, 'apparatus_id');
    }
}
