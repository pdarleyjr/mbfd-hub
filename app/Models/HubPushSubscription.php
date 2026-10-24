<?php

declare(strict_types=1);

namespace App\Models;

use NotificationChannels\WebPush\PushSubscription;

/**
 * The package's push subscription plus device health the Hub records for each
 * delivery attempt (see App\Services\Notifications\PushDeliveryOutcomeRecorder).
 *
 * @property \Carbon\CarbonInterface|null $last_success_at
 * @property \Carbon\CarbonInterface|null $last_failure_at
 * @property int $failure_count
 * @property string|null $last_failure_reason
 * @property \Carbon\CarbonInterface|null $backoff_until
 * @property string|null $user_agent
 * @property string|null $device_label
 * @property string|null $session_binding sha256 of the canonical session this device was enrolled from
 */
class HubPushSubscription extends PushSubscription
{
    /** @var list<string> */
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
        'device_label',
        'session_binding',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'backoff_until' => 'datetime',
            'failure_count' => 'integer',
        ];
    }
}
