<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\SnipeItService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SnipeItAuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('localCalendarCases')]
    public function test_maintenance_start_date_and_fallback_reference_use_the_eastern_calendar(string $instant, string $day): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse($instant));
        $apparatus = Apparatus::query()->create(['unit_id' => 'DATE1', 'designation' => 'DATE1', 'status' => 'In Service', 'snipeit_asset_id' => 42]);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id, 'operator_name' => 'Calendar fixture', 'rank' => 'Firefighter', 'unit_number' => 'DATE1',
            'results' => [['title' => 'Cab', 'items' => [['name' => 'Radio', 'status' => 'Damaged']]]],
            'completed_at' => now(),
        ]);
        $expectedReference = 'INS-'.$day.'-'.$inspection->id;
        $expectedLabel = CarbonImmutable::parse($instant)->timezone(\App\Support\HubTimezone::NAME)->format('Y-m-d H:i');
        $snipe = Mockery::mock(SnipeItService::class);
        $snipe->shouldReceive('getAssetsCheckedOutTo')->once()->with(42)->andReturn([['id' => 91, 'asset_tag' => 'RADIO91', 'name' => 'Radio']]);
        $snipe->shouldReceive('auditAsset')->once()->withArgs(fn (string $tag, string $note): bool => $tag === 'RADIO91'
            && str_contains($note, $expectedReference) && str_contains($note, 'Date: '.$expectedLabel))->andReturn([]);
        $snipe->shouldReceive('updateAssetStatus')->once()->with(91, (int) config('snipeit.status_ids.out_for_repair'))->andReturn([]);
        $snipe->shouldReceive('createMaintenanceRecord')->once()->withArgs(fn (int $id, string $name, string $type, string $notes, string $startDate): bool => $id === 91 && $type === 'Repair' && $startDate === $day && str_contains($notes, $expectedReference))->andReturn([]);

        (new AuditEquipmentAfterInspection($inspection->id, $apparatus->id))->handle($snipe);

        self::assertSame(CarbonImmutable::parse($instant)->utc()->format('Y-m-d H:i:s'), $inspection->fresh()->getRawOriginal('completed_at'));
    }

    public static function localCalendarCases(): array
    {
        return [
            ['2026-10-26T03:59:59Z', '2026-10-25'], ['2026-10-26T04:00:00Z', '2026-10-26'],
            ['2026-01-01T04:59:59Z', '2025-12-31'], ['2026-01-01T05:00:00Z', '2026-01-01'],
            ['2026-03-08T06:59:59Z', '2026-03-08'], ['2026-03-08T07:00:00Z', '2026-03-08'],
            ['2026-11-01T05:59:59Z', '2026-11-01'], ['2026-11-01T06:00:00Z', '2026-11-01'],
        ];
    }

    public function test_manifest_asset_not_present_on_checklist_is_not_audited_as_inspected(): void
    {
        $station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1051 Jefferson Avenue',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::create([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => '1001',
            'designation' => 'E1',
            'slug' => 'engine-1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'snipeit_asset_id' => 42,
            'snipeit_asset_tag' => 'APP-42',
        ]);
        $inspection = ApparatusInspection::create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Firefighter Test',
            'rank' => 'Firefighter',
            'unit_number' => '1001',
            'inspection_reference' => 'INS-E1-2026-08-10-0001',
            'results' => [[
                'title' => 'Cab',
                'items' => [[
                    'name' => 'Flashlight',
                    'status' => 'Present',
                ]],
            ]],
            'completed_at' => now(),
        ]);

        $snipeIt = Mockery::mock(SnipeItService::class);
        $snipeIt->shouldReceive('getAssetsCheckedOutTo')->once()->with(42)->andReturn([
            ['id' => 9001, 'asset_tag' => 'EQ-9001', 'name' => 'Thermal Imaging Camera'],
        ]);
        $snipeIt->shouldNotReceive('auditAsset');
        $snipeIt->shouldNotReceive('updateAssetStatus');
        $snipeIt->shouldNotReceive('createMaintenanceRecord');

        (new AuditEquipmentAfterInspection($inspection->id, $apparatus->id))->handle($snipeIt);

        $this->addToAssertionCount(1);
    }
}
