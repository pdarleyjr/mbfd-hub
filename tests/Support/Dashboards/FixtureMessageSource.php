<?php

declare(strict_types=1);

namespace Tests\Support\Dashboards;

use App\Support\Dashboards\DashboardView;

/** TEST-ONLY synthetic Support Services conversations (type "support_message", see FixtureIssueSource). */
final class FixtureMessageSource extends FixtureIssueSource
{
    public function type(): string
    {
        return 'support_message';
    }

    public function views(): array
    {
        return [DashboardView::Overview, DashboardView::StationOperations];
    }

    protected function items(): array
    {
        return [
            ['id' => '301', 'title' => 'Boot size exchange', 'summary' => 'Asking to swap a size 10 for an 11', 'severity' => 'medium', 'stage' => 'awaiting_response', 'tags' => ['supplies'], 'subject' => null, 'imageKey' => null, 'actor' => 'Sample Member One', 'category' => 'Uniforms', 'minutesAgo' => 15],
            ['id' => '302', 'title' => 'Locker key request', 'summary' => 'New locker assignment', 'severity' => 'low', 'stage' => 'in_progress', 'tags' => ['access_it'], 'subject' => null, 'imageKey' => null, 'actor' => 'Sample Member Two', 'category' => 'Station access', 'minutesAgo' => 1600],
            ['id' => '303', 'title' => 'Glove sizes for the next order', 'summary' => null, 'severity' => 'low', 'stage' => 'resolved', 'tags' => ['supplies'], 'subject' => null, 'imageKey' => null, 'actor' => 'Sample Member Three', 'category' => 'PPE', 'minutesAgo' => 100],
        ];
    }
}
