<?php

declare(strict_types=1);

namespace App\Services\Exports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * One typed spreadsheet cell: text, number, calendar date or date-time.
 *
 * Dates and date-times are stored as Excel serial numbers (a float) so a large export does
 * not hold thousands of date objects; date-times are the America/New_York wall clock.
 */
final class ExportCell
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    private function __construct(
        public readonly string $kind,
        public readonly string|int|float $value,
    ) {}

    public static function text(?string $value): self
    {
        return new self(self::TEXT, $value ?? '');
    }

    public static function blank(): self
    {
        return new self(self::TEXT, '');
    }

    public static function number(int|float $value): self
    {
        return new self(self::NUMBER, $value);
    }

    /** A calendar date with no time or time zone meaning: the date as written on the value. */
    public static function date(DateTimeInterface $value): self
    {
        $day = CarbonImmutable::create((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'), 0, 0, 0, 'UTC');

        return new self(self::DATE, (float) Date::PHPToExcel($day));
    }

    /** An instant, stored as its America/New_York wall clock. */
    public static function dateTime(DateTimeInterface $value): self
    {
        return new self(self::DATETIME, (float) Date::PHPToExcel(CarbonImmutable::instance($value)->setTimezone(TableExportService::TIMEZONE)));
    }

    public function isBlank(): bool
    {
        return $this->kind === self::TEXT && $this->value === '';
    }

    /** Human display, used by PDF output and column-width estimation. */
    public function display(): string
    {
        return match ($this->kind) {
            self::NUMBER => is_int($this->value) ? (string) $this->value : rtrim(rtrim(number_format((float) $this->value, 4, '.', ','), '0'), '.'),
            self::DATE => Date::excelToDateTimeObject((float) $this->value)->format('M j, Y'),
            self::DATETIME => Date::excelToDateTimeObject((float) $this->value)->format('M j, Y g:i A'),
            default => (string) $this->value,
        };
    }
}
