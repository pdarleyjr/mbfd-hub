<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
