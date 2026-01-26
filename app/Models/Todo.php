<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Todo extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'is_completed',
        'sort',
        'assigned_to',
        'created_by',
        'completed_at',
        'attachments',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'sort' => 'integer',
        'assigned_to' => 'array',
        'attachments' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the users assigned to this todo
     */
    public function getAssigneesAttribute(): Collection
    {
        $ids = $this->assigned_to ?? [];
        if (empty($ids)) {
            return collect();
        }
        return User::whereIn('id', $ids)->get();
    }

    /**
     * Get assignee names as a comma-separated string
     */
    public function getAssigneeNamesAttribute(): string
    {
        return $this->assignees->pluck('name')->join(', ') ?: 'Unassigned';
    }
}
