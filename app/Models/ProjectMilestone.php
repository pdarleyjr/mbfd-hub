<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMilestone extends Model
{
    protected $fillable = [
        'capital_project_id',
        'title',
        'description',
        'due_date',
        'completed_at',
        'status'
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime'
    ];

    public function capitalProject(): BelongsTo
    {
        return $this->belongsTo(CapitalProject::class);
    }
}
