<?php

declare(strict_types=1);

namespace Tests\Unit\Ems;

use App\Services\Ems\SupplyData\SupplyDataFilters;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SupplyDataFiltersTest extends TestCase
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-02 12:00:00', 'America/New_York');
    }

    /** @param array<string, mixed> $input */
    private function make(array $input = []): SupplyDataFilters
    {
        return SupplyDataFilters::fromArray($input, $this->now());
    }

    public function test_the_default_is_the_last_30_days_and_the_30_days_before_them(): void
    {
        $f = $this->make();

        $this->assertSame('30d', $f->period);
        $this->assertSame(['key' => '30d', 'from' => '2026-10-04', 'to' => '2026-11-02', 'days' => 30, 'previous' => ['from' => '2026-09-04', 'to' => '2026-10-03']], $f->periodSummary());
        $this->assertSame('week_start', $f->bucketKey());
    }

    public function test_90_day_and_12_month_periods(): void
    {
        $ninety = $this->make(['period' => '90d'])->periodSummary();
        $this->assertSame(['2026-08-05', '2026-11-02', 90], [$ninety['from'], $ninety['to'], $ninety['days']]);
        $this->assertSame(['2026-05-07', '2026-08-04'], [$ninety['previous']['from'], $ninety['previous']['to']]);

        $year = $this->make(['period' => '12m']);
        $this->assertSame(['2025-11-03', '2026-11-02', 365], [$year->from->toDateString(), $year->to->toDateString(), $year->days]);
        $this->assertSame('month_start', $year->bucketKey(), 'a year is bucketed by month');
    }

    public function test_a_custom_range_is_inclusive_and_blank_inputs_are_ignored(): void
    {
        $f = $this->make(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-07', 'unit' => '', 'item' => '', 'category' => ' ']);

        $this->assertSame(7, $f->days);
        $this->assertSame(['from' => '2026-09-24', 'to' => '2026-09-30'], $f->periodSummary()['previous']);
        $this->assertSame(['unit' => null, 'item' => null, 'category' => null], $f->filterSummary());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInputs(): array
    {
        return [
            'unknown period' => [['period' => 'forever'], 'period'],
            'custom without dates' => [['period' => 'custom'], 'from'],
            'custom without end' => [['period' => 'custom', 'from' => '2026-10-01'], 'from'],
            'from after to' => [['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01'], 'to'],
            'future end date' => [['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-11-03'], 'to'],
            'span over 400 days' => [['period' => 'custom', 'from' => '2025-09-01', 'to' => '2026-10-10'], 'to'],
            'not a date' => [['period' => 'custom', 'from' => '10/01/2026', 'to' => '2026-10-10'], 'from'],
            'impossible date' => [['period' => 'custom', 'from' => '2026-02-30', 'to' => '2026-10-10'], 'from'],
            'unit not a key' => [['unit' => 'rescue 2'], 'unit'],
            'unit zero' => [['unit' => 'unit:0'], 'unit'],
            'unit injection' => [['unit' => "unit:1' OR 1=1"], 'unit'],
            'item not a number' => [['item' => 'abc'], 'item'],
            'item as array' => [['item' => [1, 2]], 'item'],
            'category as array' => [['category' => ['x']], 'category'],
            'category too long' => [['category' => str_repeat('x', 81)], 'category'],
            'limit too big' => [['limit' => 51], 'limit'],
            'limit zero' => [['limit' => 0], 'limit'],
        ];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('badInputs')]
    public function test_bad_input_is_refused_with_a_message_for_the_field(array $input, string $field): void
    {
        try {
            $this->make($input);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_exactly_400_days_ending_today_is_allowed(): void
    {
        $f = $this->make(['period' => 'custom', 'from' => '2025-09-29', 'to' => '2026-11-02']);

        $this->assertSame(400, $f->days);
        $this->assertTrue($f->isMonthly());
    }

    public function test_today_is_decided_in_eastern_time_not_utc(): void
    {
        // 9 pm Eastern on Nov 2 is already Nov 3 in UTC, but today is still Nov 2 for the department.
        $late = SupplyDataFilters::fromArray(['period' => 'custom', 'from' => '2026-11-02', 'to' => '2026-11-02'], CarbonImmutable::parse('2026-11-02 21:00:00', 'America/New_York'));
        $this->assertSame(1, $late->days);

        $this->expectException(ValidationException::class);
        SupplyDataFilters::fromArray(['period' => 'custom', 'from' => '2026-11-03', 'to' => '2026-11-03'], CarbonImmutable::parse('2026-11-02 21:00:00', 'America/New_York'));
    }

    public function test_weekly_buckets_start_on_sunday_eastern_and_include_quiet_weeks(): void
    {
        $f = $this->make(['period' => 'custom', 'from' => '2026-10-07', 'to' => '2026-10-24']);

        $this->assertSame(['2026-10-04', '2026-10-11', '2026-10-18'], array_column($f->buckets(), 'label'));
        $this->assertSame('2026-10-07 04:00:00', $f->buckets()[0]['start']->format('Y-m-d H:i:s'), 'the first bucket is clipped to the period start');
        $this->assertSame('2026-10-11 04:00:00', $f->buckets()[1]['start']->format('Y-m-d H:i:s'));
    }

    public function test_buckets_and_period_edges_are_right_across_the_daylight_saving_change(): void
    {
        // DST ended at 2:00 am on Sunday, November 1, 2026: that Sunday is 25 hours long.
        $f = SupplyDataFilters::fromArray(['period' => 'custom', 'from' => '2026-10-28', 'to' => '2026-11-09'], CarbonImmutable::parse('2026-11-10 12:00:00', 'America/New_York'));

        $this->assertSame(['2026-10-25', '2026-11-01', '2026-11-08'], array_column($f->buckets(), 'label'));
        $starts = array_map(fn (array $b): string => $b['start']->format('Y-m-d H:i:s'), $f->buckets());
        $this->assertSame(['2026-10-28 04:00:00', '2026-11-01 04:00:00', '2026-11-08 05:00:00'], $starts, 'midnight is 04:00Z before the change and 05:00Z after it');

        $edge = $this->make(['period' => 'custom', 'from' => '2026-10-31', 'to' => '2026-11-01']);
        $this->assertSame('2026-10-31 04:00:00', $edge->fromUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 05:00:00', $edge->toUtc()->format('Y-m-d H:i:s'));
        $this->assertSame(49, (int) $edge->fromUtc()->diffInHours($edge->toUtc()), 'two calendar days across the change are 49 hours long');
        $this->assertSame(2, $edge->days);
    }

    public function test_a_year_is_bucketed_by_calendar_month(): void
    {
        $buckets = $this->make(['period' => '12m'])->buckets();

        $this->assertCount(13, $buckets);
        $this->assertSame('2025-11-01', $buckets[0]['label']);
        $this->assertSame('2026-11-01', $buckets[12]['label']);
        $this->assertSame('2025-11-03 05:00:00', $buckets[0]['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 04:00:00', $buckets[12]['start']->format('Y-m-d H:i:s'), 'November 1 starts at midnight daylight time');
    }

    public function test_the_cache_key_separates_different_questions(): void
    {
        $this->assertSame($this->make()->cacheKey(), $this->make(['period' => '30d'])->cacheKey());
        $this->assertNotSame($this->make()->cacheKey(), $this->make(['period' => '90d'])->cacheKey());
        $this->assertNotSame($this->make()->cacheKey(), $this->make(['unit' => 'detail'])->cacheKey());
    }
}
