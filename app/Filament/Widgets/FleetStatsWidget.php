<?php

namespace App\Filament\Widgets;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Services\Display\DisplaySnapshotService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'xl' => 1,
    ];

    protected function getStats(): array
    {
        $statusCounts = app(DisplaySnapshotService::class)->classifyApparatusCollection(
            Apparatus::query()->get(['id', 'status'])
        );
        $outOfService = $statusCounts['out_of_service'];
        $openDefects = ApparatusDefect::query()->unresolved()->count();
        $criticalDefects = ApparatusDefect::query()->unresolved()->missing()
            ->count();

        $defectColor = $criticalDefects > 0 ? 'danger' : ($openDefects > 0 ? 'warning' : 'success');
        $defectClass = $defectColor === 'danger'
            ? 'bg-red-50 border border-red-200 rounded-xl'
            : ($defectColor === 'warning'
                ? 'bg-amber-50 border border-amber-200 rounded-xl'
                : 'bg-green-50 border border-green-200 rounded-xl');

        return [
            Stat::make('Out of Service', $outOfService)
                ->description($outOfService > 0 ? 'Requires attention' : 'All in service')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color($outOfService > 3 ? 'danger' : ($outOfService > 0 ? 'warning' : 'success'))
                ->extraAttributes(['class' => $outOfService > 3 ? 'bg-red-50 border border-red-200 rounded-xl' : ($outOfService > 0 ? 'bg-amber-50 border border-amber-200 rounded-xl' : 'bg-green-50 border border-green-200 rounded-xl')])
                ->url(route('filament.admin.resources.apparatuses.index')),

            Stat::make('Critical Defects', $criticalDefects)
                ->description($criticalDefects > 0 ? 'Immediate fleet review required' : "{$openDefects} open defect(s)")
                ->descriptionIcon($criticalDefects > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-badge')
                ->color($defectColor)
                ->extraAttributes(['class' => $defectClass])
                ->url(route('filament.admin.resources.defects.index')),
        ];
    }
}
