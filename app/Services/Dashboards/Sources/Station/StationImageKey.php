<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Models\Station;

/**
 * "station:<n>" for a station whose approved profile photo ships at
 * public/images/stations/station-<n>.webp (the same bundled image Station::photoOrPlaceholderUrl()
 * serves on station profile pages, derived from station_number the same way). Null otherwise.
 */
final class StationImageKey
{
    /** @var array<string, bool> */
    private static array $exists = [];

    public static function for(?Station $station): ?string
    {
        $number = (string) preg_replace('/\D/', '', (string) $station?->getAttribute('station_number'));
        if ($number === '') {
            return null;
        }
        self::$exists[$number] ??= is_file(public_path('images/stations/station-'.$number.'.webp'));

        return self::$exists[$number] ? 'station:'.$number : null;
    }

    /** "Station 3" from the station number, or the station's name when it has no number. */
    public static function label(?Station $station): ?string
    {
        if ($station === null) {
            return null;
        }
        $number = trim((string) $station->getAttribute('station_number'));

        return $number !== '' ? 'Station '.$number : (trim((string) $station->getAttribute('name')) ?: null);
    }
}
