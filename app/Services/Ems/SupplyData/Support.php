<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Services\Ems\CountMath;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;

/** Small pure helpers shared by the Supply Data reports. */
final class Support
{
    /** An Eastern ISO-8601 time from a database timestamp (stored and read as UTC). */
    public static function at(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value, 'UTC')->setTimezone(HubTimezone::NAME)->toIso8601String();
    }

    /**
     * How many base units one count unit holds, and whether that is a stated fact. An item counted by the box
     * or case whose size is missing cannot be converted, so its quantities stay in individual units.
     *
     * @return array{per: int, unit: string, resolved: bool}
     */
    public static function units(?string $countUnit, ?string $packageUnit, mixed $unitsPerPackage): array
    {
        $countUnit = $countUnit ?: 'each';
        if ($countUnit === 'each') {
            return ['per' => 1, 'unit' => 'each', 'resolved' => true];
        }
        $size = (int) $unitsPerPackage;
        if ($packageUnit === $countUnit && $size >= 1) {
            return ['per' => $size, 'unit' => $countUnit, 'resolved' => true];
        }

        return ['per' => 1, 'unit' => 'each', 'resolved' => false];
    }

    /** A base quantity in count units: whole when it divides evenly, one decimal otherwise. */
    public static function amount(int|float|string|null $base, int $per): int|float|null
    {
        return $base === null ? null : CountMath::display((int) $base, max(1, $per));
    }

    /** @param list<float|int> $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return (float) (count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2);
    }

    public static function changePct(int $now, int $before): ?float
    {
        return $before === 0 ? null : round(($now - $before) / $before * 100, 1);
    }

    /** up | down | flat | new (nothing in the previous period). */
    public static function direction(int $now, int $before): string
    {
        return match (true) {
            $before === 0 => 'new',
            $now > $before => 'up',
            $now < $before => 'down',
            default => 'flat',
        };
    }

    /**
     * @param  list<array{label: string, start: CarbonImmutable}>  $buckets
     * @param  array<int, array<string, int>>  $values  bucket index => named values
     * @param  list<string>  $names
     * @return list<array<string, int|string>>
     */
    public static function fillBuckets(SupplyDataFilters $filters, array $buckets, array $values, array $names): array
    {
        $rows = [];
        foreach ($buckets as $index => $bucket) {
            $row = [$filters->bucketKey() => $bucket['label']];
            foreach ($names as $name) {
                $row[$name] = (int) ($values[$index][$name] ?? 0);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** A unit's display name: the label its requests carried, else a plain fallback. */
    public static function unitLabel(string $key, ?string $label): string
    {
        if ($label !== null && trim($label) !== '') {
            return trim($label);
        }

        return match (true) {
            $key === UnitKey::DETAIL => 'Detail / Special Event',
            $key === UnitKey::UNASSIGNED => 'Unit no longer on file',
            default => 'Unit '.UnitKey::apparatusId($key),
        };
    }
}
