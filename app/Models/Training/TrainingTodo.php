<?php

namespace App\Models\Training;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class TrainingTodo extends Model
{
    use HasFactory;

    protected $table = 'training_todos';

    protected $fillable = [
        'title',
        'description',
        'is_completed',
        'status',
        'priority',
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

    protected static function booted()
    {
        static::saving(function (TrainingTodo $todo) {
            // Sync status and is_completed
            if ($todo->isDirty('status')) {
                $todo->is_completed = $todo->status === 'completed';
            } elseif ($todo->isDirty('is_completed')) {
                $todo->status = $todo->is_completed ? 'completed' : 'pending';
            }

            if ($todo->is_completed && !$todo->completed_at) {
                $todo->completed_at = now();
            } elseif (!$todo->is_completed) {
                $todo->completed_at = null;
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TrainingTodoUpdate::class)->orderBy('created_at', 'desc');
    }

    public function getAssigneesAttribute(): Collection
    {
        $ids = $this->assigned_to ?? [];
        if (empty($ids)) {
            return collect();
        }
        $intIds = array_map('intval', $ids);
        return User::whereIn('id', $intIds)->get();
    }

    public function getAssigneeNamesAttribute(): string
    {
        return $this->assignees->pluck('name')->join(', ') ?: 'Unassigned';
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'in_progress' => 'warning',
            default => 'gray',
        };
    }

    public function getPriorityColorAttribute(): string
    {
        return match ($this->priority) {
            'urgent' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            default => 'gray',
        };
    }
}
