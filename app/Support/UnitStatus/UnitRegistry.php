<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

/**
 * The 16 apparatus on the page, in page order, and the exact matching rules for PulsePoint designations and
 * TeleStaff section labels. Matching is exact after normalization, never by substring or prefix.
 */
final class UnitRegistry
{
    /** @var list<UnitDefinition> */
    private array $units = [];

    /** @var array<string, UnitDefinition> designation => unit */
    private array $byDesignation = [];

    /** @var array<string, UnitDefinition> normalized roster label => unit */
    private array $byRosterLabel = [];

    /** @param  array<int|string, list<array<string, mixed>>>|null  $stations */
    public function __construct(?array $stations = null)
    {
        foreach ($stations ?? (array) config('unit_status.stations', []) as $number => $rows) {
            foreach ($rows as $row) {
                $unit = new UnitDefinition(
                    (string) $row['code'],
                    (string) $row['name'],
                    (int) $number,
                    array_map(static fn (string $designation): string => self::normalizeDesignation($designation)['designation'], (array) $row['designations']),
                    (string) $row['roster_label'],
                    in_array($row['class'] ?? null, [UnitDefinition::RESCUE, UnitDefinition::COMBAT], true) ? (string) $row['class'] : UnitDefinition::BY_CALL,
                );
                $this->units[] = $unit;
                foreach ($unit->designations as $designation) {
                    $this->byDesignation[$designation] = $unit;
                }
                $this->byRosterLabel[self::normalizeLabel($unit->rosterLabel)] = $unit;
            }
        }
    }

    /** @return list<UnitDefinition> */
    public function units(): array
    {
        return $this->units;
    }

    /** @return list<int> Station numbers in page order */
    public function stationNumbers(): array
    {
        return array_values(array_unique(array_map(static fn (UnitDefinition $unit): int => $unit->station, $this->units)));
    }

    public function find(string $code): ?UnitDefinition
    {
        $code = strtoupper(trim($code));
        foreach ($this->units as $unit) {
            if ($unit->code === $code) {
                return $unit;
            }
        }

        return null;
    }

    /** Resolve a PulsePoint designation such as `R1`, `?r 1` or `cpt5`; null for every other unit. */
    public function matchDesignation(string $designation): ?UnitMatch
    {
        $normalized = self::normalizeDesignation($designation);
        $unit = $normalized['designation'] === '' ? null : ($this->byDesignation[$normalized['designation']] ?? null);

        return $unit === null ? null : new UnitMatch($unit, $normalized['confirmed']);
    }

    /** Resolve a TeleStaff `unit_label` by case-insensitive exact comparison after collapsing whitespace. */
    public function matchRosterLabel(?string $label): ?UnitDefinition
    {
        return $label === null ? null : ($this->byRosterLabel[self::normalizeLabel($label)] ?? null);
    }

    /** @return array{designation: string, confirmed: bool} */
    public static function normalizeDesignation(string $designation): array
    {
        $upper = strtoupper(trim($designation));
        $confirmed = ! str_starts_with($upper, '?');
        $stripped = preg_replace('/[^A-Z0-9]/', '', $confirmed ? $upper : substr($upper, 1));

        return ['designation' => $stripped ?? '', 'confirmed' => $confirmed];
    }

    private static function normalizeLabel(string $label): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $label) ?? $label));
    }
}
