<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * Cross-apparatus identity of a checklist item (docs/apparatus-inventory-corrections.md 2.2).
 * Exact equality of an explicit key or of the normalized baseline name; never fuzzy.
 */
final class EquipmentIdentity
{
    /** @param array<string, mixed> $item */
    public static function forItem(array $item): string
    {
        $explicit = is_string($item['equipmentKey'] ?? null) ? trim($item['equipmentKey']) : '';

        return $explicit !== '' ? $explicit : 'name:'.self::normalize((string) ($item['name'] ?? ''));
    }

    public static function normalize(string $name): string
    {
        $lower = mb_strtolower($name);
        $spaced = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/u', ' ', $spaced) ?? $spaced);
    }

    /**
     * Conservative match for names members type when reporting an unlisted item: case, spacing and
     * the separators '.', '-' and '_' are ignored ("K. Tool" = "K Tool" = "K-Tool"); every other
     * character still counts ("Ladder 14'" is not "Ladder 14"). Deliberately separate from
     * normalize(), whose output is stored in equipment keys and must not change.
     */
    public static function normalizeReportedName(string $name): string
    {
        $lower = mb_strtolower($name);
        $spaced = preg_replace('/[._\-]+/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/u', ' ', $spaced) ?? $spaced);
    }

    /** The key an item added by a correction carries on every unit it is applied to. */
    public static function forAddedItem(int $correctionId): string
    {
        return 'eq:c'.$correctionId;
    }

    public static function addedItemKey(int $correctionId): string
    {
        return 'inv-c'.$correctionId;
    }
}
