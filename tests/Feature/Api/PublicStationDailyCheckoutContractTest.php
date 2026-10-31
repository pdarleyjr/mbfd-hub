<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\DailyCheckoutLedgerCutover;
use App\Models\Station;
use App\Support\HubTimezone;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicStationDailyCheckoutContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsCanonicalFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_station_show_exposes_the_canonical_daily_checkout_summary_and_matrix(): void
    {
        $station = Station::query()->create([
            'station_number' => 27,
            'name' => 'Station 27',
            'address' => '27 Test Street',
            'is_active' => true,
        ]);

        $checked = $this->apparatus($station, 'E27A');
        $attention = $this->apparatus($station, 'E27B');
        $reviewPending = $this->apparatus($station, 'E27C');
        $notChecked = $this->apparatus($station, 'E27D');
        $outOfService = $this->apparatus($station, 'E27E', 'Out of Service');
        $this->activateCutover([$checked, $attention, $reviewPending, $notChecked, $outOfService]);

        $this->inspection($checked, 'approved');
        $this->inspection($attention, 'approved');
        $this->inspection($reviewPending, 'pending_review');
        $this->inspection($outOfService, 'approved');
        ApparatusDefect::query()->create([
            'apparatus_id' => $attention->id,
            'compartment' => 'Cab',
            'item' => 'Fixture radio',
            'status' => 'Missing',
            'resolved' => false,
        ]);

        $response = $this->getJson("/api/public/stations/{$station->id}");

        $response->assertOk()
            ->assertJsonPath('daily_checkout.required_total', 4)
            ->assertJsonPath('daily_checkout.checked', 1)
            ->assertJsonPath('daily_checkout.attention', 1)
            ->assertJsonPath('daily_checkout.review_pending', 1)
            ->assertJsonPath('daily_checkout.not_checked', 1)
            ->assertJsonPath('daily_checkout.completed', 2)
            ->assertJsonPath('daily_checkout.out_of_service', 1)
            ->assertJsonPath('daily_checkout.completion_available', true);

        $dailyCheckout = $response->json('daily_checkout');
        $matrix = collect($dailyCheckout['matrix'])->keyBy('apparatus_id');

        $this->assertEquals(50.0, $dailyCheckout['completion_percent']);
        $this->assertSame(
            $dailyCheckout['required_total'],
            $dailyCheckout['checked']
                + $dailyCheckout['attention']
                + $dailyCheckout['review_pending']
                + $dailyCheckout['not_checked'],
        );
        $this->assertSame($dailyCheckout['completed'], $dailyCheckout['checked'] + $dailyCheckout['attention']);
        $this->assertSame('out_of_service', $matrix[$outOfService->id]['state']);
        $this->assertFalse($matrix[$outOfService->id]['included_in_required_total']);
        $this->assertSame('attention', $matrix[$attention->id]['state']);
        $this->assertTrue($matrix[$attention->id]['included_in_completed']);
        $this->assertTrue($matrix[$attention->id]['cutover_checkout_required']);
        $this->assertTrue($matrix[$attention->id]['cutover_checkout_verified']);
        $this->assertSame('review_pending', $matrix[$reviewPending->id]['state']);
        $this->assertSame('not_checked', $matrix[$notChecked->id]['state']);
        $this->assertArrayNotHasKey('operator_name', $matrix[$checked->id]);
    }

    public function test_station_show_marks_a_zero_required_denominator_as_unavailable(): void
    {
        $station = Station::query()->create([
            'station_number' => 28,
            'name' => 'Station 28',
            'address' => '28 Test Street',
            'is_active' => true,
        ]);

        $outOfService = $this->apparatus($station, 'E28A', 'Out of Service');
        $exempt = $this->apparatus($station, 'E28B', 'In Service', 'exempt');

        $response = $this->getJson("/api/public/stations/{$station->id}");

        $response->assertOk()
            ->assertJsonPath('daily_checkout.required_total', 0)
            ->assertJsonPath('daily_checkout.completed', 0)
            ->assertJsonPath('daily_checkout.completion_percent', null)
            ->assertJsonPath('daily_checkout.completion_available', false)
            ->assertJsonPath('daily_checkout.out_of_service', 1)
            ->assertJsonPath('daily_checkout.exempt', 1);

        $matrix = collect($response->json('daily_checkout.matrix'))->keyBy('apparatus_id');

        $this->assertSame('out_of_service', $matrix[$outOfService->id]['state']);
        $this->assertSame('exempt', $matrix[$exempt->id]['state']);
        $this->assertFalse($matrix[$outOfService->id]['included_in_required_total']);
        $this->assertFalse($matrix[$exempt->id]['included_in_required_total']);
    }

    public function test_the_station_apparatus_status_agrees_with_the_overview_on_the_7_am_to_7_am_window(): void
    {
        $station = Station::query()->create([
            'station_number' => 29,
            'name' => 'Station 29',
            'address' => '29 Test Street',
            'is_active' => true,
        ]);
        $this->at('2026-10-30 06:00:00');
        $early = $this->apparatus($station, 'E29A');
        $night = $this->apparatus($station, 'E29B');
        $this->activateCutover([$early, $night]);
        $this->inspection($early, 'approved', '2026-10-30 06:30:00');
        $moments = [
            // Before 7 AM the early checkout still belongs to the window that started the day before.
            ['2026-10-30 06:45:00', 'checked', 'not_checked'],
            ['2026-10-30 09:00:00', 'not_checked', 'not_checked'],
            ['2026-10-30 22:00:00', 'not_checked', 'checked'],
            // After midnight: the compliance day would have started over here.
            ['2026-10-31 03:00:00', 'not_checked', 'checked'],
            ['2026-10-31 07:00:00', 'not_checked', 'not_checked'],
        ];

        foreach ($moments as [$moment, $earlyState, $nightState]) {
            $this->at($moment);
            if ($moment === '2026-10-30 22:00:00') {
                $this->inspection($night, 'approved', '2026-10-30 21:30:00');
            }
            $matrix = collect($this->getJson("/api/public/stations/{$station->id}")->assertOk()->json('daily_checkout.matrix'))->keyBy('apparatus_id');
            $overview = collect($this->getJson("/api/public/stations/{$station->id}/overview")->assertOk()->json('units'))->keyBy('apparatus_id');

            $this->assertSame($earlyState, $matrix[$early->id]['state'], $moment);
            $this->assertSame($nightState, $matrix[$night->id]['state'], $moment);
            $this->assertSame($earlyState === 'checked' ? 'submitted' : 'not_submitted', $overview[$early->id]['state'], $moment);
            $this->assertSame($nightState === 'checked' ? 'submitted' : 'not_submitted', $overview[$night->id]['state'], $moment);
        }
    }

    private function at(string $eastern): void
    {
        $now = CarbonImmutable::parse($eastern, HubTimezone::NAME);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        // The member session's idle clock follows the test clock, so sign in again at each moment.
        $this->actingAsCanonicalFixture('E-'.Str::upper(Str::random(8)));
    }

    private function apparatus(
        Station $station,
        string $unit,
        string $status = 'In Service',
        string $requirement = 'required',
    ): Apparatus {
        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $unit,
            'designation' => $unit,
            'vehicle_number' => $unit,
            'make' => 'Fixture',
            'model' => 'Engine',
            'status' => $status,
            'daily_checkout_requirement' => $requirement,
        ]);
    }

    private function inspection(Apparatus $apparatus, string $reviewStatus, ?string $completedEastern = null): void
    {
        ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'client_submission_id' => (string) Str::uuid(),
            'operator_name' => 'Fixture Operator',
            'rank' => 'Firefighter',
            'shift' => 'A-Day',
            'unit_number' => $apparatus->unit_id,
            'review_status' => $reviewStatus,
            'completed_at' => $completedEastern === null ? now() : CarbonImmutable::parse($completedEastern, HubTimezone::NAME)->utc(),
        ]);
    }

    /** @param list<Apparatus> $apparatuses */
    private function activateCutover(array $apparatuses): void
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

        DailyCheckoutLedgerCutover::query()->create([
            'ledger' => DailyCheckoutLedgerCutover::LEDGER,
            'release_sha' => str_repeat('e', 40),
            'source' => DailyCheckoutLedgerCutover::SOURCE,
            'activated_at' => now()->subDay(),
            'apparatus_status_snapshot' => $snapshot,
            'snapshot_sha256' => hash('sha256', $encodedSnapshot),
            'apparatus_count' => count($snapshot),
        ]);
    }
}
