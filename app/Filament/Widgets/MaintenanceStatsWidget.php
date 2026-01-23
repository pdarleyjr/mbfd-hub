<?php

namespace App\Filament\Widgets;

use App\Models\ApparatusDefectRecommendation;
use App\Models\ApparatusInventoryAllocation;
use App\Models\ShopWork;
use Filament\Widgets\Widget;

class MaintenanceStatsWidget extends Widget
{
    protected static string $view = 'filament.widgets.maintenance-stats-widget';
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 1;  // Half-width for 2-column grid

    public function getViewData(): array
    {
        $pendingRecommendations = ApparatusDefectRecommendation::where('status', 'pending')
            ->with(['defect.apparatus', 'equipmentItem'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($rec) => [
                'id' => $rec->id,
                'unit' => $rec->defect?->apparatus?->unit_id ?? 'N/A',
                'defect' => $rec->defect?->item ?? 'Unknown',
                'recommended' => $rec->equipmentItem?->name ?? 'Not specified',
                'confidence' => $rec->match_confidence,
            ])
            ->toArray();
        
        $recentAllocations = ApparatusInventoryAllocation::where('allocated_at', '>=', now()->subDays(7))
            ->with(['apparatus', 'equipmentItem'])
            ->orderBy('allocated_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($alloc) => [
                'id' => $alloc->id,
                'unit' => $alloc->apparatus?->unit_id ?? 'N/A',
                'item' => $alloc->equipmentItem?->name ?? 'Unknown',
                'qty' => $alloc->qty_allocated,
                'date' => $alloc->allocated_at,
            ])
            ->toArray();
        
        $activeShopWork = [];
        try {
            $activeShopWork = ShopWork::whereIn('status', ['Pending', 'In Progress', 'Waiting for Parts'])
                ->with('apparatus')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($work) => [
                    'id' => $work->id,
                    'project' => $work->project_name,
                    'unit' => $work->apparatus?->unit_id ?? 'N/A',
                    'status' => $work->status,
                ])
                ->toArray();
        } catch (\Exception $e) {
            // ShopWork table may not exist
        }
        
        return [
            'pendingRecommendationsCount' => count($pendingRecommendations),
            'pendingRecommendations' => $pendingRecommendations,
            'recentAllocationsCount' => count($recentAllocations),
            'recentAllocations' => $recentAllocations,
            'activeShopWorkCount' => count($activeShopWork),
            'activeShopWork' => $activeShopWork,
        ];
    }
}
