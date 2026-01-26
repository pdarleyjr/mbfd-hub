<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $appends = ['assignee_names'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TodoUpdate::class)->orderBy('created_at', 'desc');
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
        // Cast string IDs to integers for proper whereIn comparison
        $intIds = array_map('intval', $ids);
        return User::whereIn('id', $intIds)->get();
    }

    /**
     * Get assignee names as a comma-separated string
     */
    public function getAssigneeNamesAttribute(): string
    {
        return $this->assignees->pluck('name')->join(', ') ?: 'Unassigned';
    }
}
