<?php

declare(strict_types=1);

namespace Tests\Support\Dashboards;

use App\Support\Dashboards\DashboardView;

/** TEST-ONLY synthetic apparatus checkout items (see FixtureIssueSource). */
final class FixtureVehicleSource extends FixtureIssueSource
{
    public function type(): string
    {
        return 'fixture_apparatus';
    }

    public function views(): array
    {
        return [DashboardView::Overview, DashboardView::VehicleCheckout];
    }

    protected function items(): array
    {
        return [
            ['id' => '101', 'title' => 'Suction unit not holding a charge', 'summary' => 'Battery drops below half within an hour', 'severity' => 'high', 'stage' => 'awaiting_response', 'tags' => ['rescue'], 'subject' => 'Rescue 11', 'imageKey' => 'apparatus:rescue', 'actor' => 'Fixture Member A', 'category' => 'Daily checkout · Medical', 'minutesAgo' => 25],
            ['id' => '102', 'title' => 'Bilge alarm sounding intermittently', 'summary' => 'Alarm clears after restart', 'severity' => 'high', 'stage' => 'awaiting_response', 'tags' => ['marine'], 'subject' => 'Fireboat 4', 'imageKey' => 'apparatus:fireboat', 'actor' => 'Fixture Member B', 'category' => 'Daily checkout · Marine', 'minutesAgo' => 70],
            ['id' => '103', 'title' => 'Pump panel gauge reads low', 'summary' => 'Gauge off by roughly 10 psi against the master', 'severity' => 'medium', 'stage' => 'in_progress', 'tags' => ['engine_puc'], 'subject' => 'Engine 3', 'imageKey' => 'apparatus:engine', 'actor' => 'Fixture Member C', 'category' => 'Daily checkout · Apparatus', 'minutesAgo' => 140],
            ['id' => '104', 'title' => 'Tip light lens cracked', 'summary' => null, 'severity' => 'low', 'stage' => 'awaiting_response', 'tags' => ['ladder'], 'subject' => 'Ladder 1', 'imageKey' => 'apparatus:ladder-1', 'actor' => 'Fixture Member D', 'category' => 'Daily checkout · Apparatus', 'minutesAgo' => 1500],
            ['id' => '105', 'title' => 'Portable radio charger not charging', 'summary' => 'Left bay of the charger only', 'severity' => 'medium', 'stage' => 'awaiting_response', 'tags' => ['special'], 'subject' => 'Command 300', 'imageKey' => 'apparatus:command-300', 'actor' => 'Fixture Member E', 'category' => 'Daily checkout · Equipment', 'minutesAgo' => 300],
            ['id' => '106', 'title' => 'Trauma shears restocked', 'summary' => 'Two pairs added from supply', 'severity' => 'low', 'stage' => 'resolved', 'tags' => ['rescue'], 'subject' => 'Rescue 21', 'imageKey' => 'apparatus:rescue', 'actor' => 'Fixture Member F', 'category' => 'Daily checkout · Medical', 'minutesAgo' => 50],
            ['id' => '107', 'title' => 'Mirror bracket loose', 'summary' => 'Passenger side', 'severity' => 'low', 'stage' => 'in_progress', 'tags' => ['engine_puc'], 'subject' => 'Utility 7', 'imageKey' => null, 'actor' => null, 'category' => 'Daily checkout · Apparatus', 'minutesAgo' => 4000],
            ['id' => '108', 'title' => 'Oxygen cylinder below refill mark', 'summary' => 'Cylinder 2 at 900 psi', 'severity' => 'medium', 'stage' => 'awaiting_response', 'tags' => ['rescue'], 'subject' => 'Rescue 31', 'imageKey' => 'apparatus:rescue', 'actor' => 'Fixture Member G', 'category' => 'Daily checkout · Medical', 'minutesAgo' => 200],
        ];
    }
}
