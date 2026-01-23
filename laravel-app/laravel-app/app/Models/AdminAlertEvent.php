<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AdminAlertEvent extends Model
{
    protected $fillable = [
        'type',
        'severity',
        'message',
        'related_type',
        'related_id',
        'is_read',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    /**
     * Polymorphic relation to related entity
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created this alert
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Scope for unread alerts
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope for critical severity
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Mark as read
     */
    public function markAsRead(): void
    {
        $this->update(['is_read' => true]);
    }
}
