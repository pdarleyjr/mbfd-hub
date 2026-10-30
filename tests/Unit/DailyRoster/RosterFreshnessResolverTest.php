<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** 2026-10-30 is a B shift duty day; the roster here was exported at 09:14 ET and nobody in it is real. */
final class RosterFreshnessResolverTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-30';

    private const EXPORTED_AT = '2026-10-30 13:14:15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 14:00:00', 'America/New_York'));
    }

    public function test_a_pinned_hold_is_an_administrators_decision_not_trouble(): void
    {
        $this->publish();
        $this->held(['error_code' => 'pinned', 'source_generated_at' => '2026-10-30 15:00:00']);

        self::assertSame(RosterFreshness::CURRENT, $this->state());
    }

    public function test_a_hold_with_no_error_code_still_counts(): void
    {
        $this->publish();
        $this->held(['source_generated_at' => '2026-10-30 15:00:00']);

        self::assertSame(RosterFreshness::NEEDS_REVIEW, $this->state());
    }

    public function test_a_late_arriving_older_export_does_not_count_even_though_it_arrived_last(): void
    {
        $this->publish();
        $this->held(['source_generated_at' => '2026-10-30 12:30:00', 'received_at' => '2026-10-30 17:00:00']);

        self::assertSame(RosterFreshness::CURRENT, $this->state());
    }

    public function test_an_export_stamped_exactly_at_the_effective_time_does_not_count(): void
    {
        $this->publish();
        $this->held(['source_generated_at' => self::EXPORTED_AT, 'received_at' => '2026-10-30 17:00:00']);

        self::assertSame(RosterFreshness::CURRENT, $this->state());
    }

    public function test_a_newer_export_counts_even_though_it_arrived_before_the_last_confirmation(): void
    {
        $this->publish();
        RosterActiveVersion::query()->whereDate('duty_date', self::DATE)->update(['last_confirmed_at' => '2026-10-30 16:00:00']);
        $this->held(['source_generated_at' => '2026-10-30 14:00:00', 'received_at' => '2026-10-30 14:05:00']);

        self::assertSame(RosterFreshness::NEEDS_REVIEW, $this->state());
    }

    public function test_an_export_never_read_falls_back_to_when_it_arrived(): void
    {
        $this->publish();
        $this->held(['duty_date' => null, 'received_at' => '2026-10-30 13:00:00']);
        self::assertSame(RosterFreshness::CURRENT, $this->state());

        $this->held(['duty_date' => null, 'received_at' => '2026-10-30 14:30:00']);
        self::assertSame(RosterFreshness::NEEDS_REVIEW, $this->state());
    }

    public function test_an_export_still_being_retried_is_not_needs_review_until_it_fails_for_good(): void
    {
        $this->publish();
        $import = $this->held(['state' => RosterImport::STATE_RETRYABLE_FAILED, 'attempts' => 1, 'received_at' => '2026-10-30 14:30:00']);
        self::assertSame(RosterFreshness::CURRENT, $this->state());

        $import->forceFill(['state' => RosterImport::STATE_PERMANENT_FAILED])->save();
        self::assertSame(RosterFreshness::NEEDS_REVIEW, $this->state());
    }

    public function test_an_export_still_being_retried_leaves_an_empty_morning_awaiting(): void
    {
        $this->publish('2026-10-29');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'America/New_York'));
        $this->held(['state' => RosterImport::STATE_RETRYABLE_FAILED, 'attempts' => 1, 'received_at' => '2026-10-30 12:50:00']);

        self::assertSame(RosterFreshness::AWAITING, $this->state());
    }

    public function test_the_hours_before_the_duty_day_start_follow_the_configured_hour(): void
    {
        $this->publish('2026-10-28');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 07:30:00', 'America/New_York'));

        // 07:30 still belongs to the 29th, already past its cutoff.
        self::assertSame(RosterFreshness::DELAYED, $this->state());

        config(['captain5.duty_day_start_hour' => 7]);
        self::assertSame(self::DATE, app(RosterFreshnessResolver::class)->status()->dutyDate);
        self::assertSame(RosterFreshness::AWAITING, $this->state(), 'With a 07:00 start, 07:30 is the new duty day, before its cutoff.');

        config(['captain5.duty_day_start_hour' => 8]);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 08:00:00', 'America/New_York'));
        self::assertSame(RosterFreshness::AWAITING, $this->state());

        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', 'America/New_York'));
        self::assertSame(RosterFreshness::DELAYED, $this->state());
    }

    private function state(): string
    {
        return app(RosterFreshnessResolver::class)->status()->state;
    }

    private function publish(string $dutyDate = self::DATE): void
    {
        DailyRosterSnapshotFactory::publish($dutyDate, ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]], [
            'generated_at' => $dutyDate === self::DATE ? self::EXPORTED_AT : null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function held(array $attributes = []): RosterImport
    {
        return RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => RosterImport::STATE_NEEDS_REVIEW,
            'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', (string) Str::uuid()),
            'sender' => 'sender@example.test',
            'received_at' => now(),
            'duty_date' => self::DATE,
            ...$attributes,
        ]);
    }
}
