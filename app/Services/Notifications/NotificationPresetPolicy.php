<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * What a member gets before choosing anything (owner-directed, October 2026): the bell is on for every
 * category, phone (push) alerts are on wherever a category can send them, and the city email is on in
 * addition for the important and critical categories plus Support Services messages.
 *
 * This only sets which channels are PREFERRED. It never decides who may receive a category (eligibility),
 * never enrolls a phone (the device still has to tap "Turn on alerts") and never reaches a member with no city
 * email on file (the roster-sourced city email is the only address these alerts use).
 *
 * Two exceptions: "Every Daily Checkout as it's submitted" would send one phone alert per apparatus checkout
 * every day and bury the real alerts, and "Fleet operational alerts" reach every active member about units they
 * may not ride, so their phone alerts stay off (the bell is on).
 */
final class NotificationPresetPolicy
{
    /** Categories whose phone alerts stay off by default. */
    public const PUSH_OFF_BY_DEFAULT = ['daily_checkout_every_submission', 'fleet_operational_alerts'];

    /** Routine categories whose email is still on by default (members should get replies by email). */
    public const EMAIL_ON_BY_DEFAULT = ['support_services_messages'];

    /** Significance levels whose email is on by default. */
    public const EMAIL_SIGNIFICANCE = ['important', 'critical'];

    /**
     * @param  list<string>  $channels  Channels the category supports.
     * @return array{database: bool, webpush: bool, email: bool}
     */
    public static function defaults(string $key, array $channels, string $significance): array
    {
        $supports = static fn (string $channel): bool => in_array($channel, $channels, true);

        return [
            NotificationCategory::CHANNEL_DATABASE => $supports(NotificationCategory::CHANNEL_DATABASE),
            NotificationCategory::CHANNEL_WEBPUSH => $supports(NotificationCategory::CHANNEL_WEBPUSH)
                && ! in_array($key, self::PUSH_OFF_BY_DEFAULT, true),
            NotificationCategory::CHANNEL_EMAIL => $supports(NotificationCategory::CHANNEL_EMAIL)
                && (in_array($significance, self::EMAIL_SIGNIFICANCE, true) || in_array($key, self::EMAIL_ON_BY_DEFAULT, true)),
        ];
    }
}
