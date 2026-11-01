<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An alert waiting for the queue to take it (App\Services\Notifications\NotificationDispatchRetries).
 *
 * @property string $payload
 * @property int $attempts
 * @property \Carbon\CarbonImmutable $available_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class NotificationDispatchRetry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // Alert text can name members and units, the same as the queue that normally carries it.
            'payload' => 'encrypted',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
        ];
    }
}
