<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\OperationalAlerts;

use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Fleet\OperationalAlerts\Concerns\BuildsOperationalAlertFixtures;
use Tests\TestCase;

/** November 1, 2026 is the night daylight saving ends. A date must stay that date, and an instant must stay that instant. */
final class OperationalAlertScheduleTest extends TestCase
{
    use BuildsOperationalAlertFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpAlertFixtures();
    }

    public function test_a_date_with_no_time_does_not_shift_with_the_app_timezone_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));

        $alert = $this->alert(null, ['scheduled_date' => '2026-11-01', 'scheduled_window' => 'afternoon'])->fresh();
        $this->assertSame('Sunday, November 1, afternoon', $alert->scheduleLabel());
        $this->assertSame('2026-11-01', $alert->scheduled_date->format('Y-m-d'));
        $this->assertNull($alert->scheduled_at, 'a missing time stays missing');

        $noWindow = $this->alert($this->e1, ['scheduled_date' => '2026-11-02'])->fresh();
        $this->assertSame('Monday, November 2', $noWindow->scheduleLabel());
    }

    public function test_an_alert_with_no_schedule_has_no_label_and_has_not_passed(): void
    {
        $alert = $this->alert()->fresh();

        $this->assertNull($alert->scheduleLabel());
        $this->assertFalse($alert->datePassed());
    }

    public function test_an_exact_instant_renders_eastern_on_both_sides_of_the_change(): void
    {
        $cases = [
            '2026-10-31T17:00:00Z' => 'Saturday, October 31, 1:00 PM',
            '2026-11-01T05:30:00Z' => 'Sunday, November 1, 1:30 AM',
            '2026-11-01T06:30:00Z' => 'Sunday, November 1, 1:30 AM',
            '2026-11-01T20:00:00Z' => 'Sunday, November 1, 3:00 PM',
            '2026-11-02T14:00:00Z' => 'Monday, November 2, 9:00 AM',
        ];

        foreach ($cases as $instant => $label) {
            $alert = $this->alert(null, ['scheduled_at' => $instant])->fresh();
            $this->assertSame($label, $alert->scheduleLabel(), $instant);
            $this->assertSame($instant, $alert->scheduled_at->utc()->toIso8601ZuluString());
            $this->alerts()->close($alert, $this->fleetManager());
        }
    }

    public function test_a_time_with_no_offset_is_read_as_eastern_and_an_offset_is_respected(): void
    {
        $winter = $this->notice(['scheduled_at' => '2026-11-01 15:00:00'])->fresh();
        $this->assertSame('2026-11-01T20:00:00Z', $winter->scheduled_at->utc()->toIso8601ZuluString(), '3 PM after the change is EST');

        $summer = $this->notice(['scheduled_at' => '2026-10-31 15:00:00'])->fresh();
        $this->assertSame('2026-10-31T19:00:00Z', $summer->scheduled_at->utc()->toIso8601ZuluString(), '3 PM before the change is EDT');

        $offset = $this->notice(['scheduled_at' => '2026-11-01T15:00:00-05:00'])->fresh();
        $this->assertSame('2026-11-01T20:00:00Z', $offset->scheduled_at->utc()->toIso8601ZuluString());
    }

    public function test_date_passed_follows_the_eastern_calendar_day_not_utc(): void
    {
        $alert = $this->alert(null, ['scheduled_date' => '2026-11-01'])->fresh();

        try {
            // 11 PM Eastern on November 1 is already November 2 in UTC. The day has not passed in Miami Beach.
            CarbonImmutable::setTestNow('2026-11-02T04:00:00Z');
            $this->assertSame('2026-11-01', HubTimezone::today()->toDateString());
            $this->assertFalse($alert->datePassed());

            CarbonImmutable::setTestNow('2026-11-02T05:30:00Z');
            $this->assertSame('2026-11-02', HubTimezone::today()->toDateString());
            $this->assertTrue($alert->datePassed());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
