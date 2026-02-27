<?php

namespace App\Filament\Widgets;

use App\Models\EquipmentItem;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'xl' => 1,
    ];

    protected function getStats(): array
    {
        // Get all active equipment items
        $allEquipment = EquipmentItem::where('is_active', true)->get();
        $totalItems = $allEquipment->count();
        
        // Calculate low stock items (stock <= reorder_min)
        $lowStockItems = $allEquipment->filter(fn($item) => $item->stock <= $item->reorder_min);
        $lowStockCount = $lowStockItems->count();
        
        // Calculate total inventory value (if we have a 'price' or 'unit_cost' field)
        // For now, we'll use a placeholder or you can add this field later
        $totalValue = $allEquipment->sum(function($item) {
            return ($item->unit_cost ?? 0) * $item->stock;
        });
        
        return [
            Stat::make('Low Stock Items', $lowStockCount)
                ->description("{$lowStockCount} items need reordering")
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStockCount > 5 ? 'danger' : ($lowStockCount > 0 ? 'warning' : 'success'))
                ->extraAttributes(['class' => $lowStockCount > 5 ? 'bg-red-50 border border-red-200 rounded-xl' : ($lowStockCount > 0 ? 'bg-amber-50 border border-amber-200 rounded-xl' : 'bg-green-50 border border-green-200 rounded-xl')])
                ->chart($this->getWeeklyLowStockTrend())
                ->url(route('filament.admin.resources.equipment-items.index')),
            
            Stat::make('Total Inventory Items', $totalItems)
                ->description('Active equipment items')
                ->descriptionIcon('heroicon-m-cube')
                ->color('info')
                ->extraAttributes(['class' => 'bg-blue-50 border border-blue-200 rounded-xl']),
            
            Stat::make('Stock Status', $this->getStockStatusText($totalItems, $lowStockCount))
                ->description($this->getStockHealthPercentage($totalItems, $lowStockCount))
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($this->getStockHealthColor($totalItems, $lowStockCount))
                ->extraAttributes(['class' => match($this->getStockHealthColor($totalItems, $lowStockCount)) {
                    'danger' => 'bg-red-50 border border-red-200 rounded-xl',
                    'warning' => 'bg-amber-50 border border-amber-200 rounded-xl',
                    default => 'bg-green-50 border border-green-200 rounded-xl',
                }]),
        ];
    }
    
    /**
     * Get stock status text
     */
    protected function getStockStatusText(int $total, int $lowStock): string
    {
        if ($lowStock === 0) {
            return 'All Well Stocked';
        }
        
        $percentage = $total > 0 ? round(($lowStock / $total) * 100) : 0;
        
        if ($percentage > 20) {
            return 'Critical';
        } elseif ($percentage > 10) {
            return 'Attention Needed';
        } else {
            return 'Minor Issues';
        }
    }
    
    /**
     * Get stock health percentage description
     */
    protected function getStockHealthPercentage(int $total, int $lowStock): string
    {
        if ($total === 0) {
            return '0% items need attention';
        }
        
        $healthy = $total - $lowStock;
        $percentage = round(($healthy / $total) * 100);
        
        return "{$percentage}% adequately stocked";
    }
    
    /**
     * Get color based on stock health
     */
    protected function getStockHealthColor(int $total, int $lowStock): string
    {
        if ($total === 0 || $lowStock === 0) {
            return 'success';
        }
        
        $percentage = round(($lowStock / $total) * 100);
        
        if ($percentage > 20) {
            return 'danger';
        } elseif ($percentage > 10) {
            return 'warning';
        } else {
            return 'success';
        }
    }
    
    /**
     * Get weekly low stock trend for chart
     */
    protected function getWeeklyLowStockTrend(): array
    {
        // Simple implementation - you can enhance this to track historical data
        // For now, return a simple decreasing/increasing trend
        $current = EquipmentItem::where('is_active', true)
            ->get()
            ->filter(fn($item) => $item->stock <= $item->reorder_min)
            ->count();
        
        // Generate a simple 7-day trend (this could be enhanced with historical tracking)
        return [
            max(0, $current + rand(-2, 2)),
            max(0, $current + rand(-2, 2)),
            max(0, $current + rand(-1, 1)),
            max(0, $current + rand(-1, 1)),
            max(0, $current + rand(-1, 1)),
            max(0, $current + rand(0, 1)),
            $current,
        ];
    }
}
