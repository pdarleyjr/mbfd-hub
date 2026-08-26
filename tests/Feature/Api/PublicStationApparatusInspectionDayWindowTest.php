<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\DailyCheckoutComplianceService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicStationApparatusInspectionDayWindowTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATIONAL_TIMEZONE = DailyCheckoutComplianceService::TIMEZONE;

    private Station $station;

    private Apparatus $apparatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main St',
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'phone' => '305-555-0100',
            'is_active' => true,
        ]);

        $this->apparatus = Apparatus::create([
            'station_id' => $this->station->id,
            'unit_id' => 'E1-OPERATIONAL-DAY',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => 'V100',
            'designation' => 'E1',
            'slug' => 'engine-1-operational-day',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_public_station_inspections_use_the_new_york_completed_at_operational_day(): void
    {
        $now = CarbonImmutable::parse('2026-08-25 12:00:00', self::OPERATIONAL_TIMEZONE);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $included = $this->createInspection(
            completedAt: $this->newYorkTime('2026-08-25 00:00:00'),
            createdAt: CarbonImmutable::parse('2026-08-24 23:50:00', 'UTC'),
        );
        $this->createInspection(
            completedAt: $this->newYorkTime('2026-08-24 23:59:59'),
            createdAt: CarbonImmutable::parse('2026-08-25 04:05:00', 'UTC'),
        );
        $this->createInspection(
            completedAt: $this->newYorkTime('2026-08-26 00:00:00'),
            createdAt: CarbonImmutable::parse('2026-08-25 04:10:00', 'UTC'),
        );
        $this->createInspection(
            completedAt: null,
            createdAt: CarbonImmutable::parse('2026-08-25 04:15:00', 'UTC'),
        );

        $response = $this->getJson("/api/public/stations/{$this->station->id}/apparatus-inspections");

        $response->assertOk()
            ->assertJsonPath('station_id', $this->station->id)
            ->assertJsonPath('total', 1);
        $this->assertSame([$included->id], collect($response->json('inspections'))->pluck('id')->all());
    }

    public function test_public_station_inspections_exclude_pending_review_submissions(): void
    {
        $now = CarbonImmutable::parse('2026-08-25 12:00:00', self::OPERATIONAL_TIMEZONE);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $approved = $this->createInspection(
            completedAt: $this->newYorkTime('2026-08-25 08:00:00'),
            createdAt: $this->newYorkTime('2026-08-25 08:00:00'),
        );
        $pending = $this->createInspection(
            completedAt: $this->newYorkTime('2026-08-25 09:00:00'),
            createdAt: $this->newYorkTime('2026-08-25 09:00:00'),
            reviewStatus: 'pending_review',
        );

        $response = $this->getJson("/api/public/stations/{$this->station->id}/apparatus-inspections");

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertSame([$approved->id], collect($response->json('inspections'))->pluck('id')->all());
        $this->assertNotContains($pending->id, collect($response->json('inspections'))->pluck('id')->all());
    }

    private function createInspection(
        ?CarbonImmutable $completedAt,
        CarbonImmutable $createdAt,
        string $reviewStatus = 'approved',
    ): ApparatusInspection {
        $inspection = ApparatusInspection::create([
            'apparatus_id' => $this->apparatus->id,
            'operator_name' => 'Test Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'completed_at' => $completedAt,
            'review_status' => $reviewStatus,
        ]);

        $inspection->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $inspection;
    }

    private function newYorkTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::OPERATIONAL_TIMEZONE)->utc();
    }
}
