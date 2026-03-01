<?php

namespace App\Filament\Widgets\Admin;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\EquipmentItem;
use App\Models\ApparatusInspection;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalApparatus = Apparatus::count();
        
        $outOfService = Apparatus::where('status', 'Out of Service')
            ->orWhere('status', 'out_of_service')
            ->count();
            
        $openDefects = ApparatusDefect::where('resolved', false)->count();
        
        $lowStockCount = 0;
        try {
            $lowStockItems = EquipmentItem::where('is_active', true)->get();
            $lowStockCount = $lowStockItems->filter(fn($item) => $item->stock <= $item->reorder_min)->count();
        } catch (\Exception $e) {
            // EquipmentItem table may not exist
        }
        
        $totalInventory = 0;
        try {
            $totalInventory = EquipmentItem::where('is_active', true)->count();
        } catch (\Exception $e) {
            // EquipmentItem table may not exist
        }
        
        // Calculate critical status (OOS + overdue defects + critical low stock)
        $criticalCount = $outOfService;
        
        // Overdue inspections
        $overdueInspections = Apparatus::whereDoesntHave('inspections', function($q) {
            $q->where('completed_at', '>=', now()->subDays(30));
        })->count();

        return [
            Stat::make('Total Apparatus', $totalApparatus)
                ->description('Fleet size')
                ->descriptionIcon('heroicon-o-truck')
                ->color('info'),
                
            Stat::make('Out of Service', $outOfService)
                ->description('Requires attention')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($outOfService > 0 ? 'danger' : 'success'),
                
            Stat::make('Open Defects', $openDefects)
                ->description('Unresolved issues')
                ->descriptionIcon('heroicon-o-wrench-screwdriver')
                ->color($openDefects > 5 ? 'warning' : 'success'),
                
            Stat::make('Low Stock Items', $lowStockCount)
                ->description('Below reorder threshold')
                ->descriptionIcon('heroicon-o-exclamation-circle')
                ->color($lowStockCount > 3 ? 'warning' : 'success'),
                
            Stat::make('Total Inventory Items', $totalInventory)
                ->description('Active equipment items')
                ->descriptionIcon('heroicon-o-archive-box')
                ->color('primary'),
                
            Stat::make('Critical Status', $criticalCount)
                ->description('OOS apparatus count')
                ->descriptionIcon('heroicon-o-shield-exclamation')
                ->color($criticalCount > 0 ? 'danger' : 'success'),
        ];
    }
}
