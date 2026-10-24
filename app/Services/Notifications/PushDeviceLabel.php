<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * A short, human name for a push device ("Chrome on Android") guessed from the
 * browser's user agent, so a member can recognize and remove it. The guess is
 * only a label; it never decides anything.
 */
final class PushDeviceLabel
{
    public static function fromUserAgent(?string $userAgent, ?string $hint = null): string
    {
        $hint = self::clean($hint);
        if ($hint !== '') {
            return $hint;
        }

        $agent = (string) $userAgent;
        if ($agent === '') {
            return 'Unknown device';
        }

        $platform = match (true) {
            (bool) preg_match('/iPhone/i', $agent) => 'iPhone',
            (bool) preg_match('/iPad/i', $agent) => 'iPad',
            (bool) preg_match('/Android/i', $agent) => 'Android',
            (bool) preg_match('/Windows/i', $agent) => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $agent) => 'Mac',
            (bool) preg_match('/CrOS/i', $agent) => 'Chromebook',
            (bool) preg_match('/Linux/i', $agent) => 'Linux',
            default => null,
        };

        $browser = match (true) {
            (bool) preg_match('/EdgA?\//i', $agent) => 'Edge',
            (bool) preg_match('/SamsungBrowser/i', $agent) => 'Samsung Internet',
            (bool) preg_match('/Firefox|FxiOS/i', $agent) => 'Firefox',
            (bool) preg_match('/CriOS|Chrome\//i', $agent) => 'Chrome',
            (bool) preg_match('/Safari\//i', $agent) => 'Safari',
            default => null,
        };

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $platform !== null => $platform,
            $browser !== null => $browser,
            default => 'Unknown device',
        };
    }

    private static function clean(?string $value): string
    {
        $value = trim(strip_tags((string) $value));
        $value = (string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $value);

        return mb_substr(trim($value), 0, 80);
    }
}
