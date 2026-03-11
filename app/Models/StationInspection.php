<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationInspection extends Model
{
    protected $fillable = [
        'station_id',
        'inspector_id',
        'inspection_date',
        'inspection_type',
        'form_data',
        'overall_status',
        'inspector_signature',
        'reviewer_signature',
        'reviewed_by',
        'reviewed_at',
        'notes',
        'sog_mandate_acknowledged',
        'extinguishing_system_date',
    ];

    protected $casts = [
        'form_data' => 'array',
        'inspection_date' => 'date',
        'reviewed_at' => 'datetime',
        'sog_mandate_acknowledged' => 'boolean',
        'extinguishing_system_date' => 'date',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
