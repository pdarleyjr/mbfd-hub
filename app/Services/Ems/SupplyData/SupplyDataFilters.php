<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The validated, immutable question one Supply Data call answers: a period (Eastern calendar days), the
 * comparable period just before it, and optional unit / item / category filters.
 *
 * Boundaries are Eastern midnights converted to UTC instants, so a period that spans a daylight-saving
 * change keeps its 23 or 25 hour day and never shifts an event into the wrong day or week.
 */
final class SupplyDataFilters
{
    public const PERIODS = ['30d', '90d', '12m', 'custom'];

    public const DEFAULT_PERIOD = '30d';

    public const MAX_SPAN_DAYS = 400;

    /** Up to this many days the trend is weekly; longer periods are bucketed by month. */
    public const WEEKLY_MAX_DAYS = 120;

    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 50;

    private function __construct(
        public readonly string $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $days,
        public readonly ?string $unit,
        public readonly ?int $item,
        public readonly ?string $category,
        public readonly int $limit,
        public readonly CarbonImmutable $now,
    ) {}

    /**
     * @param  array<string, mixed>  $input  period, from, to, unit, item, category, limit
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(HubTimezone::NAME);
        $input = array_map(static fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $input);
        $today = $now->startOfDay();

        $validator = Validator::make($input, [
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'unit' => ['nullable', 'string', 'regex:/^(unit:[1-9][0-9]{0,17}|detail|unassigned)$/'],
            'item' => ['nullable', 'bail', 'integer', 'min:1', Rule::exists('ems_supply_items', 'id')->where(fn ($query) => $query->where('controlled_substance', false))],
            'category' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ], [
            'period.in' => 'Choose 30 days, 90 days, 12 months or a custom range.',
            'from.date_format' => 'Enter the start date as year-month-day.',
            'to.date_format' => 'Enter the end date as year-month-day.',
            'unit.regex' => 'Choose a unit from the list, or Detail / Special Event.',
            'item.exists' => 'Choose a supply room item from the list.',
            'limit.max' => 'Ask for at most '.self::MAX_LIMIT.' rows.',
        ]);
        $validator->after(function ($validator) use ($input, $today): void {
            if (($input['period'] ?? self::DEFAULT_PERIOD) !== 'custom' || $validator->errors()->isNotEmpty()) {
                return;
            }
            if (($input['from'] ?? null) === null || ($input['to'] ?? null) === null) {
                $validator->errors()->add('from', 'A custom range needs both a start date and an end date.');

                return;
            }
            $from = self::day((string) $input['from']);
            $to = self::day((string) $input['to']);
            if ($from === null || $to === null) {
                $validator->errors()->add('from', 'Enter real calendar dates.');
            } elseif ($from->greaterThan($to)) {
                $validator->errors()->add('to', 'The end date cannot be before the start date.');
            } elseif ($to->greaterThan($today)) {
                $validator->errors()->add('to', 'The end date cannot be in the future.');
            } elseif (self::daysBetween($from, $to) > self::MAX_SPAN_DAYS) {
                $validator->errors()->add('to', 'Choose a range of at most '.self::MAX_SPAN_DAYS.' days.');
            }
        });
        $valid = $validator->validate();

        $period = (string) ($valid['period'] ?? self::DEFAULT_PERIOD);
        [$from, $to] = match ($period) {
            '90d' => [$today->subDays(89), $today],
            '12m' => [$today->subMonthsNoOverflow(12)->addDay()->startOfDay(), $today],
            'custom' => [self::day((string) $valid['from']) ?? $today, self::day((string) $valid['to']) ?? $today],
            default => [$today->subDays(29), $today],
        };

        return new self(
            $period, $from, $to, self::daysBetween($from, $to),
            $valid['unit'] ?? null,
            isset($valid['item']) ? (int) $valid['item'] : null,
            isset($valid['category']) ? trim((string) $valid['category']) : null,
            (int) ($valid['limit'] ?? self::DEFAULT_LIMIT),
            $now,
        );
    }

    /** The same period and filters with a different unit, used by the unit page. */
    public function forUnit(string $unitKey): self
    {
        return new self($this->period, $this->from, $this->to, $this->days, $unitKey, $this->item, $this->category, $this->limit, $this->now);
    }

    /** The same period and filters narrowed to one supply item, used by the item page. */
    public function forItem(int $itemId): self
    {
        return new self($this->period, $this->from, $this->to, $this->days, $this->unit, $itemId, $this->category, $this->limit, $this->now);
    }

    /** First instant of the period (UTC). */
    public function fromUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    /** The instant just after the last day of the period (UTC, exclusive). */
    public function toUtc(): CarbonImmutable
    {
        return $this->to->addDay()->startOfDay()->utc();
    }

    /** First instant of the comparable period just before this one (UTC). */
    public function previousFromUtc(): CarbonImmutable
    {
        return $this->previousFrom()->startOfDay()->utc();
    }

    /** The instant just after the last day of the previous period (UTC, exclusive) - the start of this one. */
    public function previousToUtc(): CarbonImmutable
    {
        return $this->fromUtc();
    }

    public function previousFrom(): CarbonImmutable
    {
        return $this->from->subDays($this->days)->startOfDay();
    }

    public function previousTo(): CarbonImmutable
    {
        return $this->from->subDay()->startOfDay();
    }

    public function isMonthly(): bool
    {
        return $this->days > self::WEEKLY_MAX_DAYS;
    }

    /** "week_start" or "month_start": the key the trend rows use. */
    public function bucketKey(): string
    {
        return $this->isMonthly() ? 'month_start' : 'week_start';
    }

    /**
     * Trend buckets covering the period, zero-activity ones included. Weeks start on Sunday (Eastern); months on
     * the first. Each bucket reports the date it is named for and its UTC start, clipped to the period.
     *
     * @return list<array{label: string, start: CarbonImmutable}>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor = $this->isMonthly() ? $this->from->startOfMonth() : $this->from->startOfWeek(CarbonImmutable::SUNDAY);
        while ($cursor->lessThanOrEqualTo($this->to)) {
            $start = $cursor->greaterThan($this->from) ? $cursor : $this->from;
            $buckets[] = ['label' => $cursor->toDateString(), 'start' => $start->startOfDay()->utc()];
            $cursor = $this->isMonthly() ? $cursor->addMonthNoOverflow()->startOfMonth() : $cursor->addWeek()->startOfWeek(CarbonImmutable::SUNDAY);
        }

        return $buckets;
    }

    /** @return array{key: string, from: string, to: string, days: int, previous: array{from: string, to: string}} */
    public function periodSummary(): array
    {
        return [
            'key' => $this->period, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'days' => $this->days,
            'previous' => ['from' => $this->previousFrom()->toDateString(), 'to' => $this->previousTo()->toDateString()],
        ];
    }

    /** @return array{unit: string|null, item: int|null, category: string|null} */
    public function filterSummary(): array
    {
        return ['unit' => $this->unit, 'item' => $this->item, 'category' => $this->category];
    }

    /** A stable text key for these filters (for a cache or an export file name). */
    public function cacheKey(): string
    {
        return implode('|', [
            $this->period, $this->from->toDateString(), $this->to->toDateString(),
            $this->unit ?? '-', $this->item ?? '-', $this->category ?? '-', $this->limit,
        ]);
    }

    /** Human text for the filters, printed on exports. */
    public function describe(): string
    {
        $parts = ["{$this->from->format('M j, Y')} to {$this->to->format('M j, Y')} ({$this->days} days, Eastern time)"];
        $this->unit !== null && $parts[] = 'unit '.$this->unit;
        $this->item !== null && $parts[] = 'item #'.$this->item;
        $this->category !== null && $parts[] = 'category '.$this->category;

        return implode('; ', $parts);
    }

    private static function day(string $value): ?CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, HubTimezone::NAME);

        return $date === null || $date->format('Y-m-d') !== $value ? null : $date->startOfDay();
    }

    /** Whole calendar days from one Eastern date to another, inclusive (a DST day is still one day). */
    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) CarbonImmutable::parse($from->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($to->toDateString(), 'UTC')) + 1;
    }
}
