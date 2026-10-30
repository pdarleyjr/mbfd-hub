<?php

declare(strict_types=1);

namespace App\Services\EmployeeMessaging;

/**
 * The reasons a member can pick when starting a Support Services conversation.
 *
 * One catalog for validation, the composer's choices, labels in lists/cards and the inbox filter.
 * Keys are persisted in employee_logistics_threads.reason: never rename or reuse one. A thread
 * with a null reason (every conversation started before reasons existed) reads as before.
 */
final class EmployeeMessageReason
{
    public const GENERAL = 'general';

    public const EQUIPMENT = 'equipment';

    public const UNIFORMS = 'uniforms';

    public const SUPPLIES = 'supplies';

    public const BUNKER_GEAR_CLEANING = 'bunker_gear_cleaning';

    /** @var array<string, string> key => label, in display order */
    private const LABELS = [
        self::GENERAL => 'General question',
        self::EQUIPMENT => 'Equipment',
        self::UNIFORMS => 'Uniforms',
        self::SUPPLIES => 'Supplies',
        self::BUNKER_GEAR_CLEANING => 'Bunker gear cleaning request',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::LABELS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::LABELS);
    }

    public static function label(?string $key): ?string
    {
        return $key !== null ? (self::LABELS[$key] ?? null) : null;
    }

    /**
     * Keys whose label contains the (already lower-cased) search term, so "bunker" finds the
     * conversations about bunker gear even when the member typed no subject.
     *
     * @return list<string>
     */
    public static function keysMatching(string $lowerTerm): array
    {
        $matches = [];
        foreach (self::LABELS as $key => $label) {
            if ($lowerTerm !== '' && str_contains(mb_strtolower($label), $lowerTerm)) {
                $matches[] = $key;
            }
        }

        return $matches;
    }
}
