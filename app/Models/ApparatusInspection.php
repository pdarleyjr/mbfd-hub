<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApparatusInspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_submission_id',
        'checklist_version',
        'apparatus_id',
        'operator_name',
        'rank',
        'shift',
        'unit_number',
        'engine_hours',
        'miles',
        'vehicle_number',
        'designation_at_time',
        'results',
        'officer_signature',
        'employee_id',
        'inspection_reference',
        'review_status',
        'completed_at',
    ];

    protected $casts = [
        'results' => 'array',
        'engine_hours' => 'decimal:1',
        'miles' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function apparatus()
    {
        return $this->belongsTo(Apparatus::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function defects(): HasMany
    {
        return $this->hasMany(ApparatusDefect::class);
    }
}
