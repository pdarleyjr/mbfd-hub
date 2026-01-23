<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'capital_project_id',
        'title',
        'description',
        'due_date',
        'completed',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function project()
    {
        return $this->belongsTo(CapitalProject::class, 'capital_project_id');
    }

    public function capitalProject()
    {
        return $this->belongsTo(CapitalProject::class, 'capital_project_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('completed', false);
    }

    public function scopeCompleted($query)
    {
        return $query->where('completed', true);
    }

    public function scopeOverdue($query)
    {
        return $query->where('completed', false)
            ->where('due_date', '<', now());
    }
}
