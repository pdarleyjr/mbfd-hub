<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Plain firehouse wording for the Medical Supply Data screen. It only formats what
 * App\Services\Ems\SupplyData\SupplyDataReport already worked out: nothing here calculates a figure.
 *
 * `null` from the report means "cannot be determined" and is shown as a dash with a reason nearby;
 * `0` is a real zero and is shown as 0.
 */
final class SupplyDataLabels
{
    public const DASH = "\u{2014}";

    /** The wording the report puts next to every distribution figure. */
    public const DISTRIBUTION_NOTE = 'Distributed from the supply room — not patient use';

    /** @var array<string, array{0: string, 1: string, 2: string}> basis => [label, badge colour, one-line meaning] */
    public const BASIS = [
        'counted' => ['Counted', 'success', 'A physical count, with nothing issued or received since.'],
        'estimate' => ['Estimated', 'warning', 'A best-guess starting figure from a spreadsheet, not a physical count. It stays an estimate until the shelf is counted.'],
        'calculated' => ['Calculated', 'info', 'The last count plus what has been received and issued since.'],
        'none' => ['Not counted yet', 'gray', 'No count or estimate exists for this supply.'],
    ];

    /** @var array<string, array{0: string, 1: string}> status => [label, badge colour] */
    public const STATUS = [
        'zero' => ['Out of stock', 'danger'],
        'below_minimum' => ['Below minimum', 'danger'],
        'ok' => ['OK', 'success'],
        'unknown' => ['Unknown', 'gray'],
    ];

    /** @var array<string, array{0: string, 1: string}> direction => [label, badge colour] */
    public const DIRECTION = [
        'up' => ['Up', 'info'],
        'down' => ['Down', 'gray'],
        'flat' => ['No change', 'gray'],
        'new' => ['New this period', 'info'],
    ];

    /** @var array<string, string> */
    public const PERIODS = [
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '12m' => 'Last 12 months',
        'custom' => 'Custom dates',
    ];

    /** @var array<string, array{0: string, 1: string}> key => [label, plain-language explanation] */
    public const ATTENTION = [
        'zero_stock' => ['Out of stock', 'Supplies counted, calculated or estimated at zero or below.'],
        'below_minimum' => ['Below minimum', 'Supplies under their minimum level.'],
        'needs_reorder' => ['Need reordering', 'Supplies below their order level, so the advisor suggests an order. Exactly at the level is not flagged.'],
        'estimated' => ['Estimated balances', 'Supplies whose balance is a best guess because nobody has counted the shelf.'],
        'not_counted_recently' => ['Not counted recently', 'Supplies with no physical count in the recent past.'],
    ];

    /** A whole number or a one-decimal figure; null is a dash. */
    public static function number(int|float|string|null $value, int $decimals = 1): string
    {
        if ($value === null || $value === '') {
            return self::DASH;
        }
        $number = (float) $value;
        if (floor($number) === $number) {
            return number_format($number, 0);
        }

        return rtrim(rtrim(number_format($number, $decimals), '0'), '.');
    }

    /** "+33.3%" / "-12%"; null (nothing earlier to compare with) is a dash. */
    public static function percent(int|float|null $value): string
    {
        if ($value === null) {
            return self::DASH;
        }

        return ($value > 0 ? '+' : '').self::number($value).'%';
    }

    /** An Eastern calendar date such as "Oct 30, 2026"; null or unreadable is a dash. */
    public static function date(?string $iso): string
    {
        $moment = self::moment($iso);

        return $moment === null ? self::DASH : $moment->format('M j, Y');
    }

    public static function dateTime(?string $iso): string
    {
        $moment = self::moment($iso);

        return $moment === null ? self::DASH : $moment->format('M j, Y g:i A');
    }

    /** The start of a trend bucket: "Oct 5" for a week, "Oct 2026" for a month. */
    public static function bucket(string $start, bool $monthly): string
    {
        $moment = self::moment($start);
        if ($moment === null) {
            return $start;
        }

        return $moment->format($monthly ? 'M Y' : 'M j');
    }

    /** @return array{0: string, 1: string, 2: string} */
    public static function basis(?string $basis): array
    {
        return self::BASIS[$basis ?? 'none'] ?? self::BASIS['none'];
    }

    /** @return array{0: string, 1: string} */
    public static function status(?string $status): array
    {
        return self::STATUS[$status ?? 'unknown'] ?? self::STATUS['unknown'];
    }

    /** @return array{0: string, 1: string} */
    public static function direction(?string $direction): array
    {
        return self::DIRECTION[$direction ?? 'flat'] ?? self::DIRECTION['flat'];
    }

    /** The unit the supply is counted in, as the report names it ("box", "each"). */
    public static function unitWord(?string $countUnit): string
    {
        return $countUnit !== null && $countUnit !== '' ? $countUnit : 'each';
    }

    private static function moment(?string $iso): ?CarbonImmutable
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            // A calendar date ("2026-10-04") is already an Eastern day; shifting it would show the day before.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) === 1) {
                return CarbonImmutable::parse($iso, EmsLabels::TIMEZONE);
            }

            return CarbonImmutable::parse($iso)->setTimezone(EmsLabels::TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }
}
