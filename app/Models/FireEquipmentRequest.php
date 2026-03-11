<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FireEquipmentRequest extends Model
{
    protected $fillable = [
        'station_id',
        'requested_by',
        'equipment_type',
        'description',
        'priority',
        'status',
        'form_data',
        'signature',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'form_data' => 'array',
        'approved_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
