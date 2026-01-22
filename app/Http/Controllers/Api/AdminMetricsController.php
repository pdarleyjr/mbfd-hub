<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusDefect;
use App\Models\EquipmentItem;
use App\Models\ApparatusDefectRecommendation;
use App\Models\ApparatusInventoryAllocation;
use Illuminate\Http\Request;

class AdminMetricsController extends Controller
{
    public function index()
    {
        return response()->json([
            // Existing metrics
            'apparatuses' => [
                'total' => Apparatus::count(),
                'in_service' => Apparatus::where('status', 'In Service')->count(),
                'out_of_service' => Apparatus::where('status', 'Out of Service')->count(),
                'maintenance' => Apparatus::where('status', 'Maintenance')->count(),
            ],
            
            'defects' => [
                'open' => ApparatusDefect::where('resolved', false)->count(),
                'critical' => ApparatusDefect::where('resolved', false)
                    ->where('status', 'Missing')->count(),
                'total' => ApparatusDefect::count(),
            ],
            
            'inspections' => [
                'today' => ApparatusInspection::whereDate('completed_at', today())->count(),
                'this_week' => ApparatusInspection::where('completed_at', '>=', now()->startOfWeek())->count(),
                'this_month' => ApparatusInspection::where('completed_at', '>=', now()->startOfMonth())->count(),
            ],
            
            // NEW: Fire Equipment Inventory metrics
            'inventory' => [
                'total_items' => EquipmentItem::where('is_active', true)->count(),
                'out_of_stock' => EquipmentItem::where('is_active', true)
                    ->where('stock', 0)->count(),
                'low_stock' => EquipmentItem::where('is_active', true)
                    ->whereColumn('stock', '<=', 'reorder_min')
                    ->where('stock', '>', 0)->count(),
                'pending_recommendations' => ApparatusDefectRecommendation::where('status', 'pending')->count(),
                'allocations_this_week' => ApparatusInventoryAllocation::where('allocated_at', '>=', now()->startOfWeek())->count(),
            ],
            
            // NEW: Top missing items (for reorder suggestions)
            'top_missing_items' => ApparatusDefect::where('resolved', false)
                ->where('status', 'Missing')
                ->select('item')
                ->groupBy('item')
                ->selectRaw('count(*) as frequency')
                ->orderByDesc('frequency')
                ->limit(5)
                ->get()
                ->pluck('frequency', 'item'),
            
            // NEW: Critical low stock items with locations
            'critical_stock_items' => EquipmentItem::where('is_active', true)
                ->whereColumn('stock', '<=', 'reorder_min')
                ->with('location')
                ->orderBy('stock', 'asc')
                ->limit(10)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->name,
                    'stock' => $item->stock,
                    'reorder_min' => $item->reorder_min,
                    'location' => $item->location?->full_location ?? 'Unknown',
                ]),
        ]);
    }
}