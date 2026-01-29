<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        // New columns from CSV
        'zone',
        'miami_beach_area',
        'munis_adopted_amended',
        'munis_transfers_in_out',
        'munis_revised_budget',
        'internal_transfers_in_out',
        'internal_revised_budget',
        'requisitions',
        'actual_expenses',
        'project_balance_savings',
        'last_comment_date',
        'latest_comment',
        'vfa_update',
        'vfa_update_date',
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
        // New casts for CSV columns
        'munis_adopted_amended' => 'decimal:2',
        'munis_transfers_in_out' => 'decimal:2',
        'munis_revised_budget' => 'decimal:2',
        'internal_transfers_in_out' => 'decimal:2',
        'internal_revised_budget' => 'decimal:2',
        'requisitions' => 'decimal:2',
        'actual_expenses' => 'decimal:2',
        'project_balance_savings' => 'decimal:2',
        'last_comment_date' => 'date',
        'vfa_update_date' => 'date',
    ];

    // Relationships
    public function updates()
    {
        return $this->hasMany(Under25kProjectUpdate::class, 'under_25k_project_id');
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
