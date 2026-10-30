<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

/**
 * Resolves a DashboardIssue::$imageKey to a same-origin image path. Keys are produced by the
 * issue sources, never taken from user input, and only bundled files that exist are returned.
 *
 *   apparatus:<class>  approved apparatus cutouts (public/images/apparatus/roster, from the official
 *                      MBFD apparatus photos): transparent, shown with object-fit: contain.
 *   station:<n>        the bundled station-profile photos (public/images/stations/station-<n>.webp,
 *                      the same artwork Station::photoOrPlaceholderUrl() uses): shown with object-fit: cover.
 *
 * Anything else (or a missing file) is null and the view renders a neutral fallback icon.
 */
final class DashboardImages
{
    /** Cutout class => available pixel widths, smallest first. */
    private const APPARATUS = [
        'engine' => [96, 192],
        'ladder-1' => [96, 192],
        'ladder-3' => [96, 192],
        'rescue' => [96, 192],
        'fireboat' => [96, 192],
        'command-300' => [120, 240],
        'captain-5' => [120, 240],
    ];

    /** @var array<string, bool> */
    private static array $exists = [];

    /** Same-origin path for $imageKey at (at least) $size CSS pixels, or null for the fallback icon. */
    public static function url(?string $imageKey, int $size): ?string
    {
        if ($imageKey === null) {
            return null;
        }

        if (preg_match('/^apparatus:([a-z0-9-]{1,32})$/', $imageKey, $m) === 1) {
            $widths = self::APPARATUS[$m[1]] ?? null;
            if ($widths === null) {
                return null;
            }
            $width = $widths[count($widths) - 1];
            foreach ($widths as $candidate) {
                if ($candidate >= $size) {
                    $width = $candidate;
                    break;
                }
            }

            return self::bundled('/images/apparatus/roster/'.$m[1].'-'.$width.'.webp');
        }

        if (preg_match('/^station:([1-9][0-9]?)$/', $imageKey, $m) === 1) {
            return self::bundled('/images/stations/station-'.$m[1].'.webp');
        }

        return null;
    }

    /** 'cutout' (contain on a neutral tile), 'photo' (cover) or null when there is no image. */
    public static function kind(?string $imageKey): ?string
    {
        if (self::url($imageKey, 1) === null) {
            return null;
        }

        return str_starts_with((string) $imageKey, 'station:') ? 'photo' : 'cutout';
    }

    private static function bundled(string $path): ?string
    {
        return (self::$exists[$path] ??= is_file(public_path(ltrim($path, '/')))) ? $path : null;
    }
}
