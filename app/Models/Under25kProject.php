<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Under25kProject extends Model
{
    use HasFactory;

    protected $table = 'under_25k_projects';

    protected $fillable = [
        'project_number',
        'name',
        'description',
        'budget_amount',
        'spend_amount',
        'status',
        'priority',
        'start_date',
        'target_completion_date',
        'actual_completion_date',
        'project_manager',
        'notes',
        'percent_complete',
        'internal_notes',
        'attachments',
        'attachment_file_names',
        'station_id',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'spend_amount' => 'decimal:2',
        'start_date' => 'date',
        'target_completion_date' => 'date',
        'actual_completion_date' => 'date',
        'percent_complete' => 'integer',
        'attachments' => 'array',
        'attachment_file_names' => 'array',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        // Global scope to filter only fire department projects
        static::addGlobalScope('fireDepartment', function (Builder $builder) {
            $builder->where(function ($query) {
                $query->where('project_number', 'LIKE', 'FIRE-%')
                      ->orWhere('project_number', 'LIKE', 'NEW-%')
                      ->orWhere('project_number', 'LIKE', '*NEW*%');
            });
        });
    }

    // Relationships
    public function updates()
    {
        return $this->hasMany(Under25kProjectUpdate::class, 'under_25k_project_id');
    }

    /**
     * Get the station that owns this project
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'Completed');
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['High', 'Critical']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('target_completion_date', '<', now())
            ->whereNull('actual_completion_date');
    }

    // Accessors
    public function getIsOverdueAttribute(): bool
    {
        return $this->target_completion_date 
            && $this->target_completion_date->isPast() 
            && !$this->actual_completion_date;
    }
}
