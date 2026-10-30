<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\RosterAnomalyGuard;
use App\Services\DailyRoster\RosterPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class RosterAnomalyGuardTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
    }

    public function test_a_clean_first_import_passes(): void
    {
        self::assertSame([], $this->check(RosterPipelineFixtures::report(self::DATE)));
    }

    public function test_the_shift_must_match_the_calendar(): void
    {
        self::assertSame([RosterAnomalyGuard::SHIFT_MISMATCH], $this->check(RosterPipelineFixtures::report(self::DATE, ['shift' => 'Z'])));
    }

    public function test_an_unconfigured_rotation_holds_rather_than_guesses(): void
    {
        $report = RosterPipelineFixtures::report(self::DATE);
        config(['captain5.shift_rotation.enabled' => false]);

        self::assertSame([RosterAnomalyGuard::SHIFT_MISMATCH], $this->check($report));
    }

    public function test_the_300_and_captain_5_sections_must_be_present(): void
    {
        self::assertSame([RosterAnomalyGuard::MISSING_300], $this->check(RosterPipelineFixtures::report(self::DATE, ['drop' => ['Division Chief 300']])));
        self::assertSame([RosterAnomalyGuard::MISSING_CAPTAIN_5], $this->check(RosterPipelineFixtures::report(self::DATE, ['drop' => ['Captain 5']])));
    }

    public function test_a_vacant_command_section_still_counts_as_present(): void
    {
        $sections = [
            RosterPipelineFixtures::section(0, 'Division Chief / Division Chief 300', [RosterPipelineFixtures::seat(0, 'Division Chief', [RosterPipelineFixtures::vacancy(30)])]),
            RosterPipelineFixtures::section(1, 'Station 2 / Captain 5', [RosterPipelineFixtures::seat(0, 'Captain', [RosterPipelineFixtures::vacancy(31)])]),
        ];

        self::assertSame([], $this->check(RosterPipelineFixtures::report(self::DATE, ['sections' => $sections])));
    }

    public function test_a_roster_with_no_entries_holds(): void
    {
        $sections = [
            RosterPipelineFixtures::section(0, 'Division Chief / Division Chief 300', []),
            RosterPipelineFixtures::section(1, 'Station 2 / Captain 5', []),
        ];

        self::assertSame([RosterAnomalyGuard::NO_ENTRIES], $this->check(RosterPipelineFixtures::report(self::DATE, ['sections' => $sections])));
    }

    public function test_an_export_stamped_after_it_arrived_holds_past_the_tolerance(): void
    {
        $inTolerance = RosterPipelineFixtures::report(self::DATE, ['generated_at' => now()->addMinutes(10)]);
        $tooLate = RosterPipelineFixtures::report(self::DATE, ['generated_at' => now()->addMinutes(10)->addSecond()]);

        self::assertSame([], $this->check($inTolerance));
        self::assertSame([RosterAnomalyGuard::GENERATED_AFTER_RECEIVED], $this->check($tooLate));
    }

    public function test_a_missing_export_time_holds(): void
    {
        self::assertSame([RosterAnomalyGuard::GENERATED_MISSING], $this->check(RosterPipelineFixtures::report(self::DATE, ['generated_at' => null])));
    }

    public function test_the_duty_date_must_be_within_the_configured_range(): void
    {
        $exportedEarlier = ['generated_at' => now()->subHour()];

        self::assertSame([], $this->check(RosterPipelineFixtures::report('2026-11-13', $exportedEarlier)));
        self::assertSame([RosterAnomalyGuard::DUTY_DATE_OUT_OF_RANGE], $this->check(RosterPipelineFixtures::report('2026-11-14', $exportedEarlier)));
        self::assertSame([], $this->check(RosterPipelineFixtures::report('2026-10-23', $exportedEarlier)));
        self::assertSame([RosterAnomalyGuard::DUTY_DATE_OUT_OF_RANGE], $this->check(RosterPipelineFixtures::report('2026-10-22', $exportedEarlier)));
    }

    public function test_range_checks_survive_the_dst_change(): void
    {
        // Across the fall-back Sunday a local-midnight difference would come out fractional.
        $this->travelTo('2026-11-02 13:00:00');
        self::assertSame([], $this->check(RosterPipelineFixtures::report('2026-10-26', ['generated_at' => now()->subHour()])));
        self::assertSame([RosterAnomalyGuard::DUTY_DATE_OUT_OF_RANGE], $this->check(RosterPipelineFixtures::report('2026-10-25', ['generated_at' => now()->subHour()])));
    }

    public function test_relative_checks_do_not_apply_to_the_first_import(): void
    {
        // Nothing to compare with: one section retained, tiny count, half the rows unknown, new layout.
        $report = RosterPipelineFixtures::report(self::DATE, ['layout' => str_repeat('b', 64), 'unknown_codes' => 7]);

        self::assertSame([], $this->check($report));
    }

    public function test_losing_too_many_previous_sections_holds(): void
    {
        $this->publishBaseline('2026-10-29', ['pad' => 2]);
        // Keeps the two command units and drops Engine 1, Ladder 1, and Engine 4: 2 of 5 retained, 40 percent.
        $sections = array_slice(RosterPipelineFixtures::defaultSections(self::DATE), 0, 2);
        $sections = [...$sections, RosterPipelineFixtures::section(2, 'Station 9 / Engine 9', [
            RosterPipelineFixtures::seat(0, 'Firefighter', [RosterPipelineFixtures::employee(self::DATE, 40, '90003', 'TESTPERSON, ZULU', 'SP')]),
            RosterPipelineFixtures::seat(1, 'Firefighter', [RosterPipelineFixtures::employee(self::DATE, 41, '90004', 'TESTPERSON, YANKEE', 'SP')]),
            RosterPipelineFixtures::seat(2, 'Firefighter', [RosterPipelineFixtures::employee(self::DATE, 42, '90005', 'TESTPERSON, XRAY', 'SP')]),
            RosterPipelineFixtures::seat(3, 'Firefighter', [RosterPipelineFixtures::employee(self::DATE, 43, '90006', 'TESTPERSON, WHISKEY', 'SP')]),
        ])];

        self::assertSame([RosterAnomalyGuard::SECTION_RETENTION_LOW], $this->check(RosterPipelineFixtures::report(self::DATE, ['sections' => $sections])));
    }

    public function test_a_small_change_in_units_does_not_hold(): void
    {
        $this->publishBaseline('2026-10-29', ['pad' => 2]);

        // Engine 4 is gone that day: 4 of 5 units retained, above the 75 percent floor.
        self::assertSame([], $this->check(RosterPipelineFixtures::report(self::DATE)));
    }

    public function test_assignment_counts_outside_the_median_range_hold(): void
    {
        $this->publishBaseline('2026-10-27', ['pad' => 13]);
        $this->publishBaseline('2026-10-28', ['pad' => 13]);
        $this->publishBaseline('2026-10-29', ['pad' => 13]);

        $reasonsFor = fn (array $options): array => $this->check(RosterPipelineFixtures::report(self::DATE, $options));

        // The median is 20 rows. Half (10) and double (40) are allowed; 9 and 41 are not.
        self::assertSame([RosterAnomalyGuard::ASSIGNMENTS_LOW], $reasonsFor(['pad' => 2]));
        self::assertSame([], $reasonsFor(['pad' => 3]));
        self::assertSame([], $reasonsFor(['pad' => 33]));
        self::assertSame([RosterAnomalyGuard::ASSIGNMENTS_HIGH], $reasonsFor(['pad' => 34]));
    }

    public function test_the_median_only_looks_at_the_configured_window_of_published_days(): void
    {
        config(['daily_roster.publication.median_window' => 2]);
        $this->publishBaseline('2026-10-26', ['pad' => 100]);
        $this->publishBaseline('2026-10-27', ['pad' => 100]);
        $this->publishBaseline('2026-10-28', ['pad' => 13]);
        $this->publishBaseline('2026-10-29', ['pad' => 13]);

        self::assertSame([], $this->check(RosterPipelineFixtures::report(self::DATE, ['pad' => 13])));
    }

    public function test_many_unknown_codes_hold_but_one_never_does(): void
    {
        $this->publishBaseline('2026-10-29');

        self::assertSame([], $this->check(RosterPipelineFixtures::report(self::DATE, ['unknown_codes' => 1])));
        self::assertSame([RosterAnomalyGuard::UNKNOWN_CODES_HIGH], $this->check(RosterPipelineFixtures::report(self::DATE, ['unknown_codes' => 3])));
    }

    public function test_a_changed_layout_holds(): void
    {
        $this->publishBaseline('2026-10-29');

        self::assertSame([RosterAnomalyGuard::LAYOUT_CHANGED], $this->check(RosterPipelineFixtures::report(self::DATE, ['layout' => str_repeat('b', 64)])));
    }

    public function test_normal_staffing_never_holds(): void
    {
        $this->publishBaseline('2026-10-29');
        $sections = RosterPipelineFixtures::defaultSections(self::DATE);
        $sections[3] = RosterPipelineFixtures::section(3, 'Station 1 / Ladder 1', [
            // The same employee twice, a vacancy, and an unknown code: all ordinary.
            RosterPipelineFixtures::seat(0, 'Firefighter', [RosterPipelineFixtures::employee(self::DATE, 40, '90001', RosterPipelineFixtures::FAKE_NAMES[0], 'OT')]),
            RosterPipelineFixtures::seat(1, 'Firefighter', [RosterPipelineFixtures::vacancy(41)]),
            RosterPipelineFixtures::seat(2, 'Firefighter', [RosterPipelineFixtures::employee(self::DATE, 42, '90009', 'TESTPERSON, GOLF', 'GWA', availability: 'unknown')]),
        ]);

        self::assertSame([], $this->check(RosterPipelineFixtures::report(self::DATE, ['sections' => $sections])));
    }

    public function test_only_reason_codes_come_back(): void
    {
        $reasons = $this->check(RosterPipelineFixtures::report(self::DATE, ['shift' => 'Z', 'generated_at' => null, 'drop' => ['Captain 5']]));

        foreach ($reasons as $reason) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $reason);
        }
        self::assertSame([RosterAnomalyGuard::SHIFT_MISMATCH, RosterAnomalyGuard::MISSING_CAPTAIN_5, RosterAnomalyGuard::GENERATED_MISSING], $reasons);
    }

    public function test_a_stored_but_never_shown_version_is_not_the_yardstick(): void
    {
        $this->publishBaseline(self::DATE);
        $this->publishBaseline(self::DATE, ['generated_at' => '2026-10-30 12:30:00', 'variant' => 'OLD', 'layout' => str_repeat('c', 64)]);

        self::assertSame([], $this->check(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00', 'variant' => 'NEW'])));
    }

    /** @param array<string, mixed> $options */
    private function publishBaseline(string $dutyDate, array $options = []): void
    {
        $report = RosterPipelineFixtures::report($dutyDate, $options);
        app(RosterPublisher::class)->publish(RosterPipelineFixtures::import([
            'state' => RosterImport::STATE_VALIDATED,
            'duty_date' => $dutyDate,
            'source_generated_at' => $report->generatedAt,
        ]), $report);
    }

    /** @return list<string> */
    private function check(TeleStaffRosterReport $report): array
    {
        return app(RosterAnomalyGuard::class)->check(RosterPipelineFixtures::import(), $report);
    }
}
