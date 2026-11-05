<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Models\DailyRoster\RosterAssignment;
use App\Models\User;
use App\Services\DailyRoster\HomeDutyLines;
use App\Services\DailyRoster\HomeDutyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * The regular 300 is out on a TeleStaff code outside the frozen 22-code table (FMLA, TRNADM, ...) and a relief
 * Division Chief works the seat. Exactly one person is working, so Home names the relief instead of
 * "300: Assignment needs review". 2026-10-20 is an A shift duty day.
 */
final class Home300ReliefTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const SECTION = 'Suppression / Division Chief 300';

    private User $viewer;

    private User $regularChief;

    private User $reliefChief;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('daily_roster.card_display_enabled', true);
        $this->viewer = $this->officer('Firefighter', 'Viewer Member');
        $this->regularChief = $this->officer('Division Chief', 'Regular Chief');
        $this->reliefChief = $this->officer('Division Chief', 'Relief Chief');
    }

    private function command(string $local): array
    {
        $moment = CarbonImmutable::parse($local, 'America/New_York');
        $this->travelTo($moment);
        $this->forgetCaptain5Memo();

        return app(HomeDutyStatus::class)->forMember($this->viewer, $moment)->command;
    }

    public function test_regular_300_on_an_unlisted_leave_code_does_not_hide_the_relief_300(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-20', [self::SECTION => [
            // The seat opens with the regular chief's absence, as the real export does. "FMLA" is not in TeleStaffCodebook::TABLE.
            ['employee_id' => $this->regularChief->employee_id, 'name' => 'REGULAR, CHIEF', 'rank' => 'Division Chief', 'seat' => 0,
                'code' => 'FMLA', 'category' => 'Other', 'availability' => RosterAssignment::AVAILABILITY_UNKNOWN],
            ['employee_id' => $this->reliefChief->employee_id, 'name' => 'RELIEF, CHIEF', 'rank' => 'Division Chief', 'seat' => 0,
                'continuation' => true, 'code' => '+OT', 'category' => 'Overtime'],
        ]]);

        $command = $this->command('2026-10-20 10:30');

        self::assertSame(['text' => '300 is Division Chief Relief Chief', 'state' => HomeDutyLines::COMMAND_VERIFIED], $command);
    }
}
