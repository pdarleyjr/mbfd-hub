<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Station extends Model
{
    protected $fillable = [
        'station_number',
        'address',
        'city',
        'state',
        'zip_code',
        'captain_in_charge',
        'phone',
        'notes',
    ];

    /**
     * Get all apparatuses at this station
     */
    public function apparatuses(): HasMany
    {
        return $this->hasMany(Apparatus::class, 'current_location', 'station_number');
    }

    /**
     * Get all single gas meters through apparatuses at this station
     */
    public function singleGasMeters(): HasManyThrough
    {
        return $this->hasManyThrough(
            SingleGasMeter::class,
            Apparatus::class,
            'current_location', // Foreign key on apparatus table
            'apparatus_id',     // Foreign key on single_gas_meters table
            'station_number',   // Local key on stations table
            'id'                // Local key on apparatus table
        );
    }
}
