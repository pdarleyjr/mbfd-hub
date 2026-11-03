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
