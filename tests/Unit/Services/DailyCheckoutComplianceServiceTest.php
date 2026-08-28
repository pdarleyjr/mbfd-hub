<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\Station;
use App\Services\DailyCheckoutComplianceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyCheckoutComplianceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_reconcilable_canonical_matrix_and_excludes_oos_and_exempt_apparatus(): void
    {
        $this->activateBaseCutover();
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
        $inactiveInService = $this->makeApparatus($station, 'Support 3', 'inactive', 'In Service');

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

        $this->assertSame(2, $summary['required_total']);
        $this->assertSame(1, $summary['checked']);
        $this->assertSame(0, $summary['attention']);
        $this->assertSame(0, $summary['review_pending']);
        $this->assertSame(1, $summary['not_checked']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(50.0, $summary['completion_percent']);
        $this->assertTrue($summary['completion_available']);
        $this->assertSame(2, $summary['out_of_service']);
        $this->assertSame(4, $summary['exempt']);
        $this->assertSame(1, $summary['classification_required']);
        $this->assertSame(
            $summary['required_total'],
            $summary['checked'] + $summary['attention'] + $summary['review_pending'] + $summary['not_checked'],
        );
        $this->assertSame($summary['completed'], $summary['checked'] + $summary['attention']);
        $this->assertSame($summary['required_total'], $matrix->where('included_in_required_total', true)->count());
        $this->assertSame($summary['completed'], $matrix->where('included_in_completed', true)->count());

        // Temporary compatibility aliases remain numerically equivalent while
        // consumers move to the canonical contract above.
        $this->assertSame(2, $summary['required_count']);
        $this->assertSame(1, $summary['completed_required_count']);
        $this->assertSame(1, $summary['checked_count']);
        $this->assertSame(1, $summary['missing_required_count']);
        $this->assertSame(1, $summary['unknown_count']);
        $this->assertSame(1, $summary['exempt_count']);
        $this->assertSame(1, $summary['reserve_count']);
        $this->assertSame(1, $summary['administrative_count']);
        $this->assertSame(2, $summary['inactive_count']);
        $this->assertSame(5, $summary['not_required_count']);
        $this->assertSame(2, $summary['out_of_service_count']);
        $this->assertSame('out_of_service', $matrix[$outOfService->id]['state']);
        $this->assertTrue($matrix[$outOfService->id]['out_of_service']);
        $this->assertFalse($matrix[$outOfService->id]['included_in_required_total']);
        $this->assertSame('exempt', $matrix[$exempt->id]['state']);
        $this->assertSame('exempt', $matrix[$exempt->id]['daily_checkout_requirement']);
        $this->assertSame('exempt', $matrix[$reserve->id]['state']);
        $this->assertSame('reserve', $matrix[$reserve->id]['daily_checkout_requirement']);
        $this->assertSame('exempt', $matrix[$administrative->id]['state']);
        $this->assertSame('out_of_service', $matrix[$inactive->id]['state']);
        $this->assertSame('exempt', $matrix[$inactiveInService->id]['state']);
        $this->assertSame('inactive', $matrix[$inactiveInService->id]['daily_checkout_requirement']);
        $this->assertSame('classification_required', $matrix[$unknown->id]['state']);
        $this->assertTrue($matrix[$unknown->id]['classification_required']);
        $this->assertSame([], $matrix->pluck('state')->diff([
            'checked',
            'attention',
            'review_pending',
            'not_checked',
            'out_of_service',
            'exempt',
            'classification_required',
        ])->values()->all());
    }

    public function test_pending_review_and_unresolved_missing_or_damaged_defects_are_not_checked(): void
    {
        $this->activateBaseCutover();
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

        $this->assertSame(2, $summary['required_total']);
        $this->assertSame(0, $summary['checked']);
        $this->assertSame(1, $summary['attention']);
        $this->assertSame(1, $summary['review_pending']);
        $this->assertSame(0, $summary['not_checked']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(50.0, $summary['completion_percent']);
        $this->assertSame(
            $summary['required_total'],
            $summary['checked'] + $summary['attention'] + $summary['review_pending'] + $summary['not_checked'],
        );
        $this->assertSame(0, $summary['checked_count']);
        $this->assertSame(1, $summary['attention_count']);
        $this->assertSame(1, $summary['review_pending_count']);
        $this->assertSame(0, $summary['not_checked_count']);
        $this->assertSame(1, $summary['completed_required_count']);
        $this->assertSame('review_pending', $matrix[$pendingReview->id]['state']);
        $this->assertSame('attention', $matrix[$damaged->id]['state']);
    }

    public function test_a_pending_public_submission_cannot_override_an_already_approved_checkout(): void
    {
        $this->activateBaseCutover();
        $station = Station::query()->create([
            'station_number' => 4,
            'name' => 'Station 4',
            'address' => '4 Test Street',
            'is_active' => true,
        ]);
        $apparatus = $this->makeApparatus($station, 'E41', 'required', 'In Service');
        $now = CarbonImmutable::parse('2026-08-25 12:00:00', 'America/New_York');

        $this->recordInspection(
            $apparatus,
            CarbonImmutable::parse('2026-08-25 07:00:00', 'America/New_York')->utc(),
            'approved',
        );
        $this->recordInspection(
            $apparatus,
            CarbonImmutable::parse('2026-08-25 11:00:00', 'America/New_York')->utc(),
            'pending_review',
        );

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $now,
        );
        $matrix = collect($summary['matrix'])->keyBy('apparatus_id');

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(0, $summary['review_pending']);
        $this->assertSame(1, $summary['pending_submission_count']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(0, $summary['not_checked']);
        $this->assertSame(0, $summary['review_pending_count']);
        $this->assertSame('checked', $matrix[$apparatus->id]['state']);
        $this->assertTrue($matrix[$apparatus->id]['has_pending_submission']);
    }

    public function test_it_respects_the_mbfd_dst_day_boundaries_for_completed_at(): void
    {
        $this->activateBaseCutover();
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
        $this->activateBaseCutover();
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

    public function test_it_fails_closed_when_no_daily_ledger_cutover_has_been_activated(): void
    {
        $station = Station::query()->create([
            'station_number' => 30,
            'name' => 'Station 30',
            'address' => '30 Test Street',
            'is_active' => true,
        ]);
        $apparatus = $this->makeApparatus($station, 'E301', 'required', 'In Service');
        $now = CarbonImmutable::parse('2026-08-25 12:00:00', DailyCheckoutComplianceService::TIMEZONE);
        $this->recordInspection($apparatus, CarbonImmutable::parse('2026-08-25 09:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc());

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $now,
        );
        $row = collect($summary['matrix'])->keyBy('apparatus_id')->get($apparatus->id);

        $this->assertSame(0, $summary['completed']);
        $this->assertSame(1, $summary['not_checked']);
        $this->assertSame('not_checked', $row['state']);
        $this->assertTrue($row['cutover_checkout_required']);
        $this->assertFalse($row['cutover_checkout_verified']);
        $this->assertTrue($row['cutover_activation_pending']);
        $this->assertNull($row['cutover_activated_at_utc']);
    }

    public function test_an_activated_cutover_requires_a_strictly_later_checkout_and_preserves_post_cutover_oos_rules(): void
    {
        $station = Station::query()->create([
            'station_number' => 31,
            'name' => 'Station 31',
            'address' => '31 Test Street',
            'is_active' => true,
        ]);
        $apparatus = $this->makeApparatus($station, 'E311', 'required', 'In Service');
        $preCutoverCheckoutAt = CarbonImmutable::parse('2026-08-25 07:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $cutoverAt = CarbonImmutable::parse('2026-08-25 08:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $firstQualifyingCheckoutAt = CarbonImmutable::parse('2026-08-25 08:15:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $outOfServiceAt = CarbonImmutable::parse('2026-08-25 09:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $returnedToServiceAt = CarbonImmutable::parse('2026-08-25 10:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $postReturnCheckoutAt = CarbonImmutable::parse('2026-08-25 10:15:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $summaryNow = CarbonImmutable::parse('2026-08-25 11:00:00', DailyCheckoutComplianceService::TIMEZONE);

        $this->recordInspection($apparatus, $preCutoverCheckoutAt);
        $this->activateCutover([$apparatus], $cutoverAt);

        $beforeFreshCheckout = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $beforeFreshRow = collect($beforeFreshCheckout['matrix'])->keyBy('apparatus_id')->get($apparatus->id);

        $this->assertSame('not_checked', $beforeFreshRow['state']);
        $this->assertTrue($beforeFreshRow['cutover_checkout_required']);
        $this->assertFalse($beforeFreshRow['cutover_checkout_verified']);
        $this->assertFalse($beforeFreshRow['cutover_activation_pending']);
        $this->assertSame($cutoverAt->toIso8601String(), $beforeFreshRow['cutover_activated_at_utc']);

        $this->recordInspection($apparatus, $cutoverAt);
        $sameTimestampCheckout = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $this->assertSame(0, $sameTimestampCheckout['completed']);

        $this->recordInspection($apparatus, $firstQualifyingCheckoutAt);
        $afterFreshCheckout = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $this->assertSame(1, $afterFreshCheckout['completed']);

        $this->transitionStatusAt($apparatus, 'Out of Service', $outOfServiceAt);
        $this->transitionStatusAt($apparatus, 'In Service', $returnedToServiceAt);

        $afterReturn = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $afterReturnRow = collect($afterReturn['matrix'])->keyBy('apparatus_id')->get($apparatus->id);
        $this->assertSame('not_checked', $afterReturnRow['state']);
        $this->assertTrue($afterReturnRow['return_checkout_required']);
        $this->assertFalse($afterReturnRow['return_checkout_verified']);

        $this->recordInspection($apparatus, $returnedToServiceAt);
        $sameTimestampReturnCheckout = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $this->assertSame(0, $sameTimestampReturnCheckout['completed']);

        $this->recordInspection($apparatus, $postReturnCheckoutAt);
        $afterReturnCheckout = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $afterReturnRow = collect($afterReturnCheckout['matrix'])->keyBy('apparatus_id')->get($apparatus->id);

        $this->assertSame('checked', $afterReturnRow['state']);
        $this->assertTrue($afterReturnRow['cutover_checkout_verified']);
        $this->assertTrue($afterReturnRow['return_checkout_verified']);
    }

    public function test_a_return_recorded_in_the_same_storage_second_as_cutover_remains_ledger_authoritative(): void
    {
        $station = Station::query()->create([
            'station_number' => 32,
            'name' => 'Station 32',
            'address' => '32 Test Street',
            'is_active' => true,
        ]);
        $apparatus = $this->makeApparatus($station, 'E321', 'required', 'Out of Service');
        $cutoverAt = CarbonImmutable::parse('2026-08-25 08:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $qualifyingCheckoutAt = CarbonImmutable::parse('2026-08-25 08:15:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $summaryNow = CarbonImmutable::parse('2026-08-25 09:00:00', DailyCheckoutComplianceService::TIMEZONE);

        $this->activateCutover([$apparatus], $cutoverAt);
        $this->transitionStatusAt($apparatus, 'In Service', $cutoverAt);
        $this->recordInspection($apparatus, $qualifyingCheckoutAt);

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $row = collect($summary['matrix'])->keyBy('apparatus_id')->get($apparatus->id);

        $this->assertSame('checked', $row['state']);
        $this->assertTrue($row['return_checkout_required']);
        $this->assertTrue($row['return_checkout_verified']);
    }

    public function test_zero_required_denominator_is_explicitly_unavailable(): void
    {
        $this->activateBaseCutover();
        $station = Station::query()->create([
            'station_number' => 5,
            'name' => 'Station 5',
            'address' => '5 Test Street',
            'is_active' => true,
        ]);
        $this->makeApparatus($station, 'L51', 'required', 'Out of Service');
        $this->makeApparatus($station, 'Support 51', 'exempt', 'In Service');
        $this->makeApparatus($station, 'Support 52', 'unknown', 'In Service');

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            CarbonImmutable::parse('2026-08-25 12:00:00', 'America/New_York'),
        );

        $this->assertSame(0, $summary['required_total']);
        $this->assertSame(0, $summary['completed']);
        $this->assertNull($summary['completion_percent']);
        $this->assertFalse($summary['completion_available']);
        $this->assertSame(1, $summary['out_of_service']);
        $this->assertSame(1, $summary['exempt']);
        $this->assertSame(1, $summary['classification_required']);
    }

    public function test_a_same_day_oos_return_requires_an_approved_checkout_after_the_return_transition(): void
    {
        $this->activateBaseCutover();
        $station = Station::query()->create([
            'station_number' => 6,
            'name' => 'Station 6',
            'address' => '6 Test Street',
            'is_active' => true,
        ]);
        $apparatus = $this->makeApparatus($station, 'E61', 'required', 'In Service');
        $preOosCheckoutAt = CarbonImmutable::parse('2026-08-25 07:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $outOfServiceAt = CarbonImmutable::parse('2026-08-25 08:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $returnedToServiceAt = CarbonImmutable::parse('2026-08-25 09:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $qualifyingCheckoutAt = CarbonImmutable::parse('2026-08-25 09:15:00', DailyCheckoutComplianceService::TIMEZONE)->utc();
        $summaryNow = CarbonImmutable::parse('2026-08-25 10:00:00', DailyCheckoutComplianceService::TIMEZONE);

        $this->recordInspection($apparatus, $preOosCheckoutAt);
        $this->transitionStatusAt($apparatus, 'Out of Service', $outOfServiceAt);
        $this->transitionStatusAt($apparatus, 'In Service', $returnedToServiceAt);

        $events = ApparatusOperationalStatusEvent::query()->orderBy('id')->get();
        $this->assertSame('2026-08-25T12:00:00.000000Z', $events[0]->changed_at->toISOString());
        $this->assertSame('2026-08-25T13:00:00.000000Z', $events[1]->changed_at->toISOString());

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $matrix = collect($summary['matrix'])->keyBy('apparatus_id');

        $this->assertSame('not_checked', $matrix[$apparatus->id]['state']);
        $this->assertTrue($matrix[$apparatus->id]['return_checkout_required']);
        $this->assertFalse($matrix[$apparatus->id]['return_checkout_verified']);
        $this->assertSame(1, $summary['not_checked']);
        $this->assertSame(0, $summary['checked']);

        // Matching the return timestamp does not prove a checkout happened
        // after the apparatus returned to service.
        $this->recordInspection($apparatus, $returnedToServiceAt);
        $sameTimestampCheckout = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $this->assertSame(0, $sameTimestampCheckout['checked']);
        $this->assertSame(1, $sameTimestampCheckout['not_checked']);

        $this->recordInspection($apparatus, $qualifyingCheckoutAt);

        $afterReturnCheckout = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            $summaryNow,
        );
        $afterReturnMatrix = collect($afterReturnCheckout['matrix'])->keyBy('apparatus_id');

        $this->assertSame('checked', $afterReturnMatrix[$apparatus->id]['state']);
        $this->assertTrue($afterReturnMatrix[$apparatus->id]['return_checkout_verified']);
        $this->assertSame(1, $afterReturnCheckout['checked']);
    }

    public function test_a_post_deployment_return_from_a_pre_ledger_oos_state_requires_a_new_checkout(): void
    {
        $this->activateBaseCutover();
        $station = Station::query()->create([
            'station_number' => 7,
            'name' => 'Station 7',
            'address' => '7 Test Street',
            'is_active' => true,
        ]);
        // No prior event exists: this simulates an apparatus that was already
        // OOS when the append-only ledger was introduced.
        $apparatus = $this->makeApparatus($station, 'E71', 'required', 'Out of Service');
        $this->recordInspection($apparatus, CarbonImmutable::parse('2026-08-25 11:30:00', 'UTC'));
        $this->transitionStatusAt(
            $apparatus,
            'In Service',
            CarbonImmutable::parse('2026-08-25 13:00:00', 'UTC'),
        );

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            CarbonImmutable::parse('2026-08-25 10:00:00', DailyCheckoutComplianceService::TIMEZONE),
        );
        $matrix = collect($summary['matrix'])->keyBy('apparatus_id');

        $this->assertSame('not_checked', $matrix[$apparatus->id]['state']);
        $this->assertTrue($matrix[$apparatus->id]['return_checkout_required']);
        $this->assertSame(1, $summary['not_checked']);
    }

    public function test_an_ordinary_operational_status_edit_does_not_reopen_a_prior_day_oos_episode(): void
    {
        $this->activateBaseCutover();
        $station = Station::query()->create([
            'station_number' => 8,
            'name' => 'Station 8',
            'address' => '8 Test Street',
            'is_active' => true,
        ]);
        $apparatus = $this->makeApparatus($station, 'E81', 'required', 'In Service');

        $this->transitionStatusAt($apparatus, 'Out of Service', CarbonImmutable::parse('2026-08-24 16:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc());
        $this->transitionStatusAt($apparatus, 'In Service', CarbonImmutable::parse('2026-08-24 17:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc());
        $this->recordInspection($apparatus, CarbonImmutable::parse('2026-08-25 07:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc());
        $this->transitionStatusAt($apparatus, 'Available', CarbonImmutable::parse('2026-08-25 09:00:00', DailyCheckoutComplianceService::TIMEZONE)->utc());

        $summary = app(DailyCheckoutComplianceService::class)->summaryForApparatuses(
            $station->apparatuses()->get(),
            CarbonImmutable::parse('2026-08-25 10:00:00', DailyCheckoutComplianceService::TIMEZONE),
        );
        $matrix = collect($summary['matrix'])->keyBy('apparatus_id');

        $this->assertSame('checked', $matrix[$apparatus->id]['state']);
        $this->assertFalse($matrix[$apparatus->id]['return_checkout_required']);
        $this->assertSame(1, $summary['checked']);
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

    private function transitionStatusAt(Apparatus $apparatus, string $status, CarbonImmutable $changedAt): void
    {
        $apparatus->timestamps = false;
        try {
            $apparatus->forceFill([
                'status' => $status,
                'updated_at' => $changedAt,
            ])->save();
        } finally {
            $apparatus->timestamps = true;
        }
    }

    private function activateBaseCutover(): void
    {
        $this->activateCutover([], CarbonImmutable::parse('2020-01-01T00:00:00Z'));
    }

    /** @param list<Apparatus> $apparatuses */
    private function activateCutover(array $apparatuses, CarbonImmutable $activatedAt): void
    {
        $snapshot = collect($apparatuses)
            ->sortBy('id')
            ->map(static fn (Apparatus $apparatus): array => [
                'id' => (int) $apparatus->id,
                'status' => $apparatus->status,
            ])
            ->values()
            ->all();
        $encodedSnapshot = json_encode($snapshot, JSON_THROW_ON_ERROR);

        DB::table('daily_checkout_ledger_cutovers')->insert([
            'ledger' => 'daily_checkout',
            'release_sha' => str_repeat('c', 40),
            'source' => 'owner_beta_activation',
            'activated_at' => $activatedAt,
            'apparatus_status_snapshot' => $encodedSnapshot,
            'snapshot_sha256' => hash('sha256', $encodedSnapshot),
            'apparatus_count' => count($snapshot),
            'created_at' => $activatedAt,
            'updated_at' => $activatedAt,
        ]);
    }
}
