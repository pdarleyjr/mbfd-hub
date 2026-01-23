<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTracking extends Model
{
    use HasFactory;

    protected $table = 'notification_tracking';

    protected $fillable = [
        'user_id',
        'project_id',
        'notification_type',
        'sent_at',
        'read_at',
        'actioned_at',
        'action_taken',
        'snoozed_until',
        'metadata',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'actioned_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(CapitalProject::class, 'project_id');
    }

    // Scopes
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeSnoozed($query)
    {
        return $query->where('snoozed_until', '>', now());
    }
}
