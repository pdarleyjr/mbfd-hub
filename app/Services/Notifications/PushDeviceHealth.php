<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What counts as a healthy push device: a subscription that is not waiting out a
 * short back-off (at most fifteen minutes, see PushDeliveryOutcomeRecorder). A
 * device in back-off is still a device: the member keeps the push channel, and
 * the alert is re-sent to that device when the back-off ends (HubWebPushChannel).
 * A device that keeps failing is never switched off for good; the member can
 * always remove it.
 */
final class PushDeviceHealth
{
    /** From this many failures in a row the settings page suggests removing the device and enrolling again. */
    public const SUGGEST_REPLACEMENT_AFTER = 5;

    /**
     * @template TBuilder of Builder|Relation
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function scopeHealthy(Builder|Relation $query): Builder|Relation
    {
        return $query->where(fn ($q) => $q->whereNull('backoff_until')->orWhere('backoff_until', '<=', now()));
    }

    /**
     * Devices that are waiting out a back-off right now.
     *
     * @template TBuilder of Builder|Relation
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function scopeBackedOff(Builder|Relation $query): Builder|Relation
    {
        return $query->where('backoff_until', '>', now());
    }
}
