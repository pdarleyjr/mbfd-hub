<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationTracking extends Model
{
    use HasFactory;

    protected $table = 'notification_tracking';

    protected $fillable = [
        'user_id',
        'notifiable_type',
        'notifiable_id',
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

    protected static function booted(): void
    {
        // sent_at is NOT NULL but callers don't pass it; default to creation time.
        static::creating(function (NotificationTracking $tracking) {
            if (empty($tracking->sent_at)) {
                $tracking->sent_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(CapitalProject::class, 'project_id');
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeSnoozed($query)
    {
        return $query->where('snoozed_until', '>', now());
    }
}
