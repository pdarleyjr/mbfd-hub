<?php

declare(strict_types=1);

namespace Tests\Support\Dashboards;

use App\Support\Dashboards\DashboardView;

/** TEST-ONLY synthetic station request items (see FixtureIssueSource). */
final class FixtureStationSource extends FixtureIssueSource
{
    public function type(): string
    {
        return 'fixture_station';
    }

    public function views(): array
    {
        return [DashboardView::Overview, DashboardView::StationOperations];
    }

    protected function items(): array
    {
        return [
            ['id' => '201', 'title' => 'Bay door opener not closing fully', 'summary' => 'Door stops about a foot short', 'severity' => 'high', 'stage' => 'awaiting_response', 'tags' => ['facilities'], 'subject' => 'Station 2', 'imageKey' => 'station:2', 'actor' => 'Fixture Member H', 'category' => 'Facilities / Maintenance', 'minutesAgo' => 35],
            ['id' => '202', 'title' => 'Roof leak above the apparatus bay', 'summary' => 'Bucket in place; leak after heavy rain', 'severity' => 'high', 'stage' => 'in_progress', 'tags' => ['facilities'], 'subject' => 'Station 3', 'imageKey' => 'station:3', 'actor' => 'Fixture Member I', 'category' => 'Facilities / Maintenance', 'minutesAgo' => 1440],
            ['id' => '203', 'title' => 'Kitchen supply restock', 'summary' => 'Paper goods and cleaning supplies', 'severity' => 'medium', 'stage' => 'awaiting_response', 'tags' => ['supplies'], 'subject' => 'Station 4', 'imageKey' => 'station:4', 'actor' => 'Fixture Member J', 'category' => 'Logistics / Supplies', 'minutesAgo' => 400],
            ['id' => '204', 'title' => 'Badge reader offline at side entrance', 'summary' => null, 'severity' => 'low', 'stage' => 'awaiting_response', 'tags' => ['access_it'], 'subject' => 'Station 6', 'imageKey' => 'station:6', 'actor' => 'Fixture Member K', 'category' => 'Security / Access', 'minutesAgo' => 3000],
            ['id' => '205', 'title' => 'Water heater serviced', 'summary' => 'Element replaced by the contractor', 'severity' => 'medium', 'stage' => 'resolved', 'tags' => ['facilities'], 'subject' => 'Station 1', 'imageKey' => 'station:1', 'actor' => 'Fixture Member L', 'category' => 'Facilities / Maintenance', 'minutesAgo' => 90],
            ['id' => '206', 'title' => 'Day room TV input not switching', 'summary' => null, 'severity' => 'low', 'stage' => 'in_progress', 'tags' => ['access_it'], 'subject' => 'Station 5', 'imageKey' => 'station:5', 'actor' => null, 'category' => 'IT / Communications', 'minutesAgo' => 6000],
        ];
    }
}
