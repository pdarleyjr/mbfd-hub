<?php

declare(strict_types=1);

namespace App\Services\DailyRoster\Parsing;

/**
 * The frozen TeleStaff code table (docs/operations/telestaff-daily-roster.md, "Code classes").
 *
 * A prefix counts only where the table lists it for that code, so `.SP` stays unknown and is never
 * treated as working. Anything not in the table is shown verbatim and raises a row-level review.
 */
final class TeleStaffCodebook
{
    private const DOT = '.';

    private const EXCHANGE_MARK = "\u{2927}";

    /** @var array<string, array{availability: string, category: string, label: ?string, prefixes: list<string>}> */
    private const TABLE = [
        'SP' => ['availability' => 'working', 'category' => 'Scheduled', 'label' => null, 'prefixes' => []],
        '+OT' => ['availability' => 'working', 'category' => 'Overtime', 'label' => null, 'prefixes' => []],
        'OT' => ['availability' => 'working', 'category' => 'Overtime', 'label' => null, 'prefixes' => []],
        '2WXON' => ['availability' => 'working', 'category' => 'Exchange', 'label' => null, 'prefixes' => [self::EXCHANGE_MARK]],
        '2WXEON' => ['availability' => 'working', 'category' => 'Exchange', 'label' => null, 'prefixes' => [self::EXCHANGE_MARK]],
        'ADONIS DAY' => ['availability' => 'not_working', 'category' => 'A-Day', 'label' => 'A-Day', 'prefixes' => [self::DOT]],
        'VACA' => ['availability' => 'not_working', 'category' => 'Vacation', 'label' => null, 'prefixes' => [self::DOT]],
        'VAC' => ['availability' => 'not_working', 'category' => 'Vacation', 'label' => null, 'prefixes' => [self::DOT]],
        'EVAC' => ['availability' => 'not_working', 'category' => 'Leave', 'label' => null, 'prefixes' => []],
        '2WXOFF' => ['availability' => 'not_working', 'category' => 'Exchange off', 'label' => null, 'prefixes' => [self::DOT, self::EXCHANGE_MARK]],
        '2WXEOFF' => ['availability' => 'not_working', 'category' => 'Exchange off', 'label' => null, 'prefixes' => [self::DOT, self::EXCHANGE_MARK]],
        'ADMS' => ['availability' => 'not_working', 'category' => 'Leave', 'label' => null, 'prefixes' => []],
        'MILNP' => ['availability' => 'not_working', 'category' => 'Leave', 'label' => null, 'prefixes' => []],
        'SIC' => ['availability' => 'not_working', 'category' => 'Sick', 'label' => null, 'prefixes' => []],
        'SIC RVT' => ['availability' => 'not_working', 'category' => 'Sick', 'label' => null, 'prefixes' => []],
        'PENFML' => ['availability' => 'not_working', 'category' => 'Leave', 'label' => '.PENFML', 'prefixes' => [self::DOT]],
        'DAYOFF' => ['availability' => 'not_working', 'category' => 'Day off', 'label' => 'Day off', 'prefixes' => [self::DOT]],
        '^OOC' => ['availability' => 'annotation', 'category' => 'Incentive', 'label' => null, 'prefixes' => []],
        '^ROC' => ['availability' => 'annotation', 'category' => 'Incentive', 'label' => null, 'prefixes' => []],
        '^MOC' => ['availability' => 'annotation', 'category' => 'Incentive', 'label' => null, 'prefixes' => []],
        '^TOC' => ['availability' => 'annotation', 'category' => 'Incentive', 'label' => null, 'prefixes' => []],
    ];

    public static function classify(string $rawCode): TeleStaffCodeClassification
    {
        $code = trim($rawCode);
        [$prefix, $baseCode] = self::split($code);
        $entry = self::TABLE[$baseCode] ?? null;

        if ($entry === null || ($prefix !== null && ! in_array($prefix, $entry['prefixes'], true))) {
            return new TeleStaffCodeClassification($rawCode, $baseCode, $prefix, 'unknown', 'Other', $code);
        }

        return new TeleStaffCodeClassification(
            $rawCode,
            $baseCode,
            $prefix,
            $entry['availability'],
            $entry['category'],
            $entry['label'] ?? $baseCode,
        );
    }

    /** @return array{0: ?string, 1: string} */
    private static function split(string $code): array
    {
        foreach ([self::DOT, self::EXCHANGE_MARK] as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return [$prefix, substr($code, strlen($prefix))];
            }
        }

        return [null, $code];
    }
}
