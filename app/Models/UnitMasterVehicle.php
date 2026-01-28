<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitMasterVehicle extends Model
{
    use HasFactory;

    protected $table = 'unit_master_vehicles';

    protected $fillable = [
        'veh_number',
        'make',
        'model',
        'year',
        'tag_number',
        'dept_code',
        'employee_or_vehicle_name',
        'sunpass_number',
        'als_license',
        'serial_number',
        'section',
        'assignment',
        'location',
    ];

    protected $casts = [
        'year' => 'string',
    ];
}
