<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final readonly class OperationalDisplayWindow
{
    public const TIMEZONE = 'America/New_York';

    private const START_HOUR = 8;

    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function forNow(DateTimeInterface|string|null $now = null): self
    {
        $localNow = match (true) {
            $now instanceof DateTimeInterface => CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE),
            is_string($now) => CarbonImmutable::parse($now, self::TIMEZONE)->setTimezone(self::TIMEZONE),
            default => CarbonImmutable::now(self::TIMEZONE),
        };

        $start = $localNow->setTime(self::START_HOUR, 0, 0);
        if ($localNow->lessThan($start)) {
            $start = $start->subDay();
        }

        return new self($start, $start->addDay());
    }

    public function contains(DateTimeInterface|string $value): bool
    {
        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, self::TIMEZONE);

        return $instant->greaterThanOrEqualTo($this->start) && $instant->lessThan($this->end);
    }

    public function databaseStart(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function databaseEnd(): CarbonImmutable
    {
        return $this->end->utc();
    }

    /** @return array{start: string, end: string, label: string, timezone: string} */
    public function toDisplayArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'label' => $this->start->format('D, M j · g:i A').' → '.$this->end->format('D, M j · g:i A'),
            'timezone' => self::TIMEZONE,
        ];
    }
}
