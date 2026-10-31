<?php

declare(strict_types=1);

namespace Tests\Support\Dashboards;

use App\Support\Dashboards\DashboardView;

/** TEST-ONLY: one engine with many missing items, as a real checkout reports them (see FixtureIssueSource). */
final class FixtureGroupedEngineSource extends FixtureIssueSource
{
    private const ITEMS = ['1 3/4 inch Fog Nozzle', '3 inch Fog Nozzle', 'Gated Wye', '2 1/2 inch In Line Valve', '2 1/2 inch Siamese', 'Storz to Siamese', 'Double Males and Females', 'Hydrant Wrench'];

    public function type(): string
    {
        return 'fixture_engine';
    }

    public function views(): array
    {
        return [DashboardView::Overview, DashboardView::VehicleCheckout];
    }

    protected function groupKey(): ?string
    {
        return 'apparatus:2';
    }

    protected function items(): array
    {
        $items = [];
        foreach (self::ITEMS as $index => $item) {
            $items[] = ['id' => (string) (201 + $index), 'title' => 'Missing: '.$item, 'summary' => 'Hose bed', 'severity' => 'medium', 'stage' => 'awaiting_response', 'tags' => ['engine_puc'], 'subject' => 'E 2', 'imageKey' => 'apparatus:engine', 'actor' => 'Fixture Driver', 'category' => 'Equipment issue', 'minutesAgo' => 60 + $index];
        }

        return $items;
    }
}
