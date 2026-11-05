<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

/** PulsePoint unit status words grouped into families (contract section 5). The color is an indicator only. */
final class StatusFamily
{
    public const UNKNOWN = 'unknown';

    public const CLEARED = 'cleared';

    private const WORDS = [
        'dispatched' => 'dispatched',
        'acknowledged' => 'dispatched',
        'enroute' => 'enroute',
        'staged' => 'on_scene',
        'on scene' => 'on_scene',
        'available on scene' => 'on_scene',
        'transport' => 'transport',
        'transport arrived' => 'arrived',
        'cleared from incident' => self::CLEARED,
    ];

    private const COLORS = [
        'dispatched' => 'rgb(245,124,0)',
        'enroute' => 'rgb(0,204,0)',
        'on_scene' => 'rgb(204,0,0)',
        'transport' => 'rgb(255,193,7)',
        'arrived' => 'rgb(25,118,210)',
        self::CLEARED => 'rgb(73,73,73)',
    ];

    public static function family(?string $word): string
    {
        return self::WORDS[self::key($word)] ?? self::UNKNOWN;
    }

    /**
     * The only source of status text on the board (contract 10.6). PulsePoint's own words are never shown: an unknown or
     * blank word reads "Assigned", which is all that is known about a confirmed unit on a call.
     */
    public static function label(?string $word): string
    {
        return match (self::key($word)) {
            'dispatched', 'acknowledged' => 'Dispatched',
            'enroute' => 'En Route',
            'staged' => 'Staged',
            'on scene', 'available on scene' => 'On Scene',
            'transport' => 'Transporting',
            'transport arrived' => 'At Hospital',
            'cleared from incident' => 'Cleared',
            default => 'Assigned',
        };
    }

    public static function color(string $family): ?string
    {
        return self::COLORS[$family] ?? null;
    }

    public static function isCleared(?string $word): bool
    {
        return self::family($word) === self::CLEARED;
    }

    private static function key(?string $word): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', (string) $word) ?? ''));
    }
}
