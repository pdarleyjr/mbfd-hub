<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Services\CommandCenterAiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CommandCenterCalendarTest extends TestCase
{
    use RefreshDatabase;

    public static function easternYesterday(): iterable
    {
        yield 'summer' => ['2026-10-26T00:30:00Z', '2026-10-24T04:00:00Z'];
        yield 'spring transition' => ['2026-03-09T00:30:00Z', '2026-03-07T05:00:00Z'];
        yield 'fall transition' => ['2026-11-02T00:30:00Z', '2026-10-31T04:00:00Z'];
    }

    #[DataProvider('easternYesterday')]
    public function test_inspection_lookback_begins_at_yesterday_eastern_midnight(string $now, string $boundary): void
    {
        $this->travelTo(CarbonImmutable::parse($now));
        foreach ([1, -1] as $seconds) {
            $apparatus = Apparatus::create(['unit_id' => 'CAL'.$seconds, 'status' => 'In Service']);
            $inspection = new ApparatusInspection([
                'client_submission_id' => (string) Str::uuid(), 'apparatus_id' => $apparatus->id,
                'operator_name' => 'Synthetic Calendar Fixture', 'rank' => 'Firefighter', 'shift' => 'A-Day',
                'completed_at' => CarbonImmutable::parse($boundary)->addSeconds($seconds),
            ]);
            $inspection->created_at = CarbonImmutable::parse($boundary)->addSeconds($seconds);
            $inspection->save();
        }

        self::assertSame(1, app(CommandCenterAiService::class)->gatherMetrics()['vehicle_inventory']['overdue_inspections']);
    }
}
