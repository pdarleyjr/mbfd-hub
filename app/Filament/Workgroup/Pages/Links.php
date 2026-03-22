<?php

namespace App\Filament\Workgroup\Pages;

use Filament\Pages\Page;

class Links extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Links';

    protected static ?string $title = 'Workgroup Links';

    protected static string $view = 'filament.workgroup.pages.links';

    protected static ?string $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 5;

    public function getAnalysisReportUrl(): string
    {
        return route('workgroup.analysis-report');
    }

    public function getDataDashboardUrl(): string
    {
        return route('workgroup.data-dashboard');
    }

    public function getL1InventoryUrl(): string
    {
        return route('workgroup.l1-inventory');
    }

    public function getFinalPresentationUrl(): string
    {
        return route('workgroup.final-presentation');
    }

    public function getEvaluationReportUrl(): string
    {
        return route('workgroup.evaluation-report');
    }

    public function getWorkgroupSummaryUrl(): string
    {
        return route('workgroup.workgroup-summary');
    }
}