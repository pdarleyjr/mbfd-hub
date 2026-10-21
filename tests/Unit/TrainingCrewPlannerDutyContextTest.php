<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDutyContext;
use PHPUnit\Framework\TestCase;

final class TrainingCrewPlannerDutyContextTest extends TestCase
{
    public function test_utc_session_suggestion_uses_miami_local_time_without_inventing_end(): void
    {
        $method = new \ReflectionMethod(TrainingCrewPlanner::class, 'slotEdit');
        $slot = $method->invoke(new TrainingCrewPlanner, ['id' => 'synthetic', 'label' => 'Existing class', 'start_at' => '2026-10-21T13:00:00Z', 'end_at' => null], true);
        self::assertSame('09:00', $slot['start']);
        self::assertSame('', $slot['end']);
    }

    public function test_split_assignments_follow_the_classroom_time_instead_of_first_row(): void
    {
        $contexts = [
            ['station' => 'Station Synthetic A', 'apparatus' => 'Engine Synthetic', 'start_at' => '2026-10-21T08:00:00-04:00', 'end_at' => '2026-10-21T12:00:00-04:00'],
            ['station' => 'Station Synthetic B', 'apparatus' => 'Rescue Synthetic', 'start_at' => '2026-10-21T12:00:00-04:00', 'end_at' => '2026-10-21T20:00:00-04:00'],
        ];
        $projector = new TrainingCrewPlannerDutyContext;
        $afternoon = $projector->project($contexts, ['start_at' => '2026-10-21T13:00:00-04:00', 'end_at' => '2026-10-21T16:00:00-04:00']);
        self::assertStringContainsString('Station Synthetic B', $afternoon['station']);
        self::assertStringContainsString('Rescue Synthetic', $afternoon['apparatus']);
        self::assertStringNotContainsString('Engine Synthetic', $afternoon['apparatus']);
        $morning = $projector->project($contexts, ['start_at' => '2026-10-21T08:30:00-04:00', 'end_at' => '2026-10-21T11:30:00-04:00']);
        self::assertStringContainsString('Engine Synthetic', $morning['apparatus']);
        self::assertStringNotContainsString('Rescue Synthetic', $morning['apparatus']);
        $unassigned = $projector->project($contexts, null);
        self::assertStringContainsString('Engine Synthetic (10/21 08:00–10/21 12:00)', $unassigned['apparatus']);
        self::assertStringContainsString('Rescue Synthetic (10/21 12:00–10/21 20:00)', $unassigned['apparatus']);
        $spanning = $projector->project($contexts, ['start_at' => '2026-10-21T11:00:00-04:00', 'end_at' => '2026-10-21T13:00:00-04:00']);
        self::assertStringContainsString('Engine Synthetic', $spanning['apparatus']);
        self::assertStringContainsString('Rescue Synthetic', $spanning['apparatus']);
    }
}
