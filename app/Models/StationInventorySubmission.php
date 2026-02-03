<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationInventorySubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'station_id',
        'employee_name',
        'shift',
        'items',
        'notes',
        'pdf_path',
        'created_by',
        'submitted_at',
    ];

    protected $casts = [
        'items' => 'array',
        'submitted_at' => 'datetime',
    ];

    /**
     * Get the station that owns the submission.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the user who created the submission.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}