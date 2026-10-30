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
    /** RFC 8291, which every current push service (Apple, FCM, Mozilla, WNS) accepts. */
    public const DEFAULT_CONTENT_ENCODING = 'aes128gcm';

    /**
     * Devices enrolled before the Hub recorded an encoding have NULL here, and web-push-php reads NULL as the old
     * draft "aesgcm". Every sender reads this attribute, so an unknown value means the standard encoding, using the
     * same subscription keys, and no device has to enroll again. A stored "aesgcm" (only ever sent by a browser that
     * cannot do aes128gcm) is kept as it is.
     */
    protected function contentEncoding(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(
            static fn (?string $value): string => in_array($value, ['aes128gcm', 'aesgcm'], true)
                ? $value
                : self::DEFAULT_CONTENT_ENCODING,
        );
    }

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
