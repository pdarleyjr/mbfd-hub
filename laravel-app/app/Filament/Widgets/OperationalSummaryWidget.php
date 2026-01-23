<?php

namespace App\Filament\Widgets;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use Filament\Widgets\Widget;

class OperationalSummaryWidget extends Widget
{
    protected static string $view = 'filament.widgets.operational-summary-widget';
    protected static ?int $sort = 1;
    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $totalApparatus = Apparatus::count();
        $inService = Apparatus::where('status', 'In Service')->count();
        $outOfService = Apparatus::where('status', 'Out of Service')->count();
        
        $openDefects = ApparatusDefect::where('resolved', false)->count();
        $criticalDefects = ApparatusDefect::where('resolved', false)
            ->where('issue_type', 'critical')
            ->count();
        
        $overdueInspections = Apparatus::whereDoesntHave('inspections', function($q) {
            $q->where('created_at', '>=', today()->subDay());
        })->count();
        
        // Get urgent alerts (max 5 items)
        $urgentAlerts = [];
        
        // Out of service vehicles
        $outOfServiceVehicles = Apparatus::where('status', 'Out of Service')
            ->limit(3)
            ->get(['unit_id', 'make', 'model']);
        foreach ($outOfServiceVehicles as $vehicle) {
            $urgentAlerts[] = [
                'type' => 'critical',
                'message' => "{$vehicle->unit_id} - Out of Service",
                'icon' => 'heroicon-o-exclamation-triangle',
            ];
        }
        
        // Critical defects
        $criticalDefectsList = ApparatusDefect::where('resolved', false)
            ->where('issue_type', 'critical')
            ->with('apparatus:id,unit_id')
            ->limit(2)
            ->get(['id', 'apparatus_id', 'item']);
        foreach ($criticalDefectsList as $defect) {
            $urgentAlerts[] = [
                'type' => 'warning',
                'message' => "{$defect->apparatus?->unit_id}: {$defect->item}",
                'icon' => 'heroicon-o-wrench-screwdriver',
            ];
        }
        
        return [
            'totalApparatus' => $totalApparatus,
            'inService' => $inService,
            'outOfService' => $outOfService,
            'openDefects' => $openDefects,
            'criticalDefects' => $criticalDefects,
            'overdueInspections' => $overdueInspections,
            'urgentAlerts' => array_slice($urgentAlerts, 0, 5),
        ];
    }
}
