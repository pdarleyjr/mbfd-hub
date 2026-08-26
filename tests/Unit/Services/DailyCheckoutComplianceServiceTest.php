<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\DailyCheckoutComplianceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyCheckoutComplianceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_distinct_required_apparatus_in_the_mbfd_completed_at_day_window(): void
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);

        $completedRequired = $this->makeApparatus($station, 'E1', 'required', 'In Service');
        $missingRequired = $this->makeApparatus($station, 'E2', 'required', 'In Service');
        $unknown = $this->makeApparatus($station, 'Support 1', 'unknown', 'In Service');
        $outOfService = $this->makeApparatus($station, 'L1', 'required', 'Out of Service');
        $exempt = $this->makeApparatus($station, 'Support 2', 'exempt', 'In Service');
        $reserve = $this->makeApparatus($station, 'Reserve 1', 'reserve', 'In Service');
        $administrative = $this->makeApparatus($station, 'Command 1', 'administrative', 'In Service');
        $inactive = $this->makeApparatus($station, 'Retired 1', 'inactive', 'Out of Service');

        $now = CarbonImmutable::parse('2026-08-25 12:00:00', 'America/New_York');
        $this->recordInspection($completedRequired, CarbonImmutable::parse('2026-08-25 00:05:00', 'America/New_York')->utc());
        $this->recordInspection($completedRequired, CarbonImmutable::parse('2026-08-25 08:15:00', 'America/New_York')->utc());
        $this->recordInspection($missingRequired, CarbonImmutable::parse('2026-08-24 23:59:59', 'America/New_York')->utc());
        $this->recordInspection($unknown, CarbonImmutable::parse('2026-08-25 09:00:00', 'America/New_York')->utc());
        $this->recordInspection($outOfService, CarbonImmutable::parse('2026-08-25 10:00:00', 'America/New_York')->utc());
        $this->recordInspection($exempt, CarbonImmutable::parse('2026-08-25 10:30:00', 'America/New_York')->utc());

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $now,
        );
        $matrix = collect($summary['matrix'])->keyBy('apparatus_id');

        $this->assertSame(3, $summary['required_count']);
        $this->assertSame(2, $summary['completed_required_count']);
        $this->assertSame(2, $summary['checked_count']);
        $this->assertSame(1, $summary['missing_required_count']);
        $this->assertSame(1, $summary['unknown_count']);
        $this->assertSame(1, $summary['exempt_count']);
        $this->assertSame(1, $summary['reserve_count']);
        $this->assertSame(1, $summary['administrative_count']);
        $this->assertSame(1, $summary['inactive_count']);
        $this->assertSame(4, $summary['not_required_count']);
        $this->assertSame(2, $summary['out_of_service_count']);
        $this->assertSame('checked', $matrix[$outOfService->id]['state']);
        $this->assertTrue($matrix[$outOfService->id]['out_of_service']);
        $this->assertSame('exempt', $matrix[$exempt->id]['state']);
        $this->assertSame('exempt', $matrix[$exempt->id]['daily_checkout_requirement']);
        $this->assertSame('reserve', $matrix[$reserve->id]['state']);
        $this->assertSame('reserve', $matrix[$reserve->id]['daily_checkout_requirement']);
        $this->assertSame('administrative', $matrix[$administrative->id]['state']);
        $this->assertSame('inactive', $matrix[$inactive->id]['state']);
    }

    public function test_pending_review_and_unresolved_missing_or_damaged_defects_are_not_checked(): void
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $pendingReview = $this->makeApparatus($station, 'E1', 'required', 'In Service');
        $damaged = $this->makeApparatus($station, 'E2', 'required', 'In Service');
        $now = CarbonImmutable::parse('2026-08-25 12:00:00', 'America/New_York');

        $this->recordInspection(
            $pendingReview,
            CarbonImmutable::parse('2026-08-25 08:00:00', 'America/New_York')->utc(),
            'pending_review',
        );
        $damagedInspection = $this->recordInspection(
            $damaged,
            CarbonImmutable::parse('2026-08-25 09:00:00', 'America/New_York')->utc(),
            'approved',
        );
        ApparatusDefect::query()->create([
            'apparatus_id' => $damaged->id,
            'apparatus_inspection_id' => $damagedInspection->id,
            'compartment' => 'Cab',
            'item' => 'Flashlight',
            'status' => 'Damaged',
            'resolved' => false,
        ]);

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $now,
        );
        $matrix = collect($summary['matrix'])->keyBy('apparatus_id');

        $this->assertSame(0, $summary['checked_count']);
        $this->assertSame(1, $summary['attention_count']);
        $this->assertSame(1, $summary['review_pending_count']);
        $this->assertSame(0, $summary['not_checked_count']);
        $this->assertSame('review_pending', $matrix[$pendingReview->id]['state']);
        $this->assertSame('attention', $matrix[$damaged->id]['state']);
    }

    public function test_it_respects_the_mbfd_dst_day_boundaries_for_completed_at(): void
    {
        $station = Station::query()->create([
            'station_number' => 2,
            'name' => 'Station 2',
            'address' => '2 Test Street',
            'is_active' => true,
        ]);
        $previousDay = $this->makeApparatus($station, 'E21', 'required', 'In Service');
        $localDay = $this->makeApparatus($station, 'E22', 'required', 'In Service');
        $nextDay = $this->makeApparatus($station, 'E23', 'required', 'In Service');

        // March 8, 2026 is the 23-hour spring-forward day in New York.
        $this->recordInspection($previousDay, CarbonImmutable::parse('2026-03-08 04:59:59', 'UTC'));
        $this->recordInspection($localDay, CarbonImmutable::parse('2026-03-08 05:00:00', 'UTC'));
        $this->recordInspection($nextDay, CarbonImmutable::parse('2026-03-09 04:00:00', 'UTC'));

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            CarbonImmutable::parse('2026-03-08 12:00:00', 'America/New_York'),
        );

        $this->assertSame(3, $summary['required_count']);
        $this->assertSame(1, $summary['completed_required_count']);
        $this->assertSame(1, $summary['checked_count']);
        $this->assertSame(2, $summary['not_checked_count']);
    }

    public function test_pre_cutover_inspections_without_the_canonical_submission_identifier_do_not_satisfy_daily_compliance(): void
    {
        $station = Station::query()->create([
            'station_number' => 3,
            'name' => 'Station 3',
            'address' => '3 Test Street',
            'is_active' => true,
        ]);
        $legacy = $this->makeApparatus($station, 'E31', 'required', 'In Service');
        $canonical = $this->makeApparatus($station, 'E32', 'required', 'In Service');
        $now = CarbonImmutable::parse('2026-08-25 12:00:00', 'America/New_York');

        $this->recordInspection($legacy, $now->utc(), 'approved', false);
        $this->recordInspection($canonical, $now->utc());

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $now,
        );

        $this->assertSame(2, $summary['required_count']);
        $this->assertSame(1, $summary['checked_count']);
        $this->assertSame(1, $summary['not_checked_count']);
    }

    private function makeApparatus(Station $station, string $designation, string $requirement, string $status): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $designation,
            'designation' => $designation,
            'name' => $designation,
            'type' => 'Test',
            'make' => 'Test',
            'model' => 'Test',
            'year' => 2026,
            'status' => $status,
            'daily_checkout_requirement' => $requirement,
        ]);
    }

    private function recordInspection(
        Apparatus $apparatus,
        CarbonImmutable $completedAt,
        string $reviewStatus = 'approved',
        bool $canonical = true,
    ): ApparatusInspection {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Test Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'completed_at' => $completedAt,
            'review_status' => $reviewStatus,
            'client_submission_id' => $canonical ? (string) Str::uuid() : null,
        ]);
    }
}
