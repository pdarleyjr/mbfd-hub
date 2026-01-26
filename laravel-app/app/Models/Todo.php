<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Todo extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'assigned_to',
        'assigned_by',
        'is_completed',
        'completed_at',
        'sort',
        'created_by',
        'created_by_user_id',
        'assigned_to_user_id',
        'attachments',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'due_date' => 'datetime',
        'sort' => 'integer',
        'attachments' => 'array',
        'assigned_to' => 'array',
        'status' => 'string',
    ];

    protected $attributes = [
        'status' => 'pending',
        'priority' => 'medium',
        'is_completed' => false,
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TodoUpdate::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the users this todo is assigned to
     */
    public function getAssignedUsersAttribute()
    {
        if (empty($this->assigned_to) || !is_array($this->assigned_to)) {
            return collect();
        }
        
        return User::whereIn('id', $this->assigned_to)->get();
    }

    /**
     * Get the names of users this todo is assigned to
     */
    public function getAssignedUserNamesAttribute()
    {
        return $this->getAssignedUsersAttribute()->pluck('name')->implode(', ') ?: 'Unassigned';
    }
}
