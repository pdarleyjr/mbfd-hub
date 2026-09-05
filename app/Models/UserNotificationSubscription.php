<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserNotificationSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'event_key',
        'database_enabled',
        'webpush_enabled',
        'email_enabled',
    ];

    protected function casts(): array
    {
        return [
            'database_enabled' => 'boolean',
            'webpush_enabled' => 'boolean',
            'email_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
