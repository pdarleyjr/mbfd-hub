<?php

namespace App\Filament\Widgets\Admin;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\EquipmentItem;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class OperationalAlertsWidget extends Widget
{
    protected static string $view = 'filament.widgets.admin.operational-alerts-widget';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'lg' => 3,
    ];

    public ?array $alerts = null;

    public function mount(): void
    {
        $this->loadAlerts();
    }

    public function loadAlerts(): void
    {
        $alerts = [];

        // Out of Service vehicles
        $oosVehicles = Apparatus::where('status', 'Out of Service')
            ->orWhere('status', 'out_of_service')
            ->get(['id', 'unit_id', 'make', 'model', 'notes', 'updated_at']);

        foreach ($oosVehicles as $vehicle) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'heroicon-o-truck',
                'title' => 'Out of Service',
                'message' => "{$vehicle->unit_id} - {$vehicle->make} {$vehicle->model}",
                'details' => $vehicle->notes,
                'time' => $vehicle->updated_at?->diffForHumans() ?? 'Unknown',
            ];
        }

        // Open critical defects
        $criticalDefects = ApparatusDefect::where('resolved', false)
            ->where('status', 'critical')
            ->with('apparatus:id,unit_id')
            ->limit(5)
            ->get();

        foreach ($criticalDefects as $defect) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'title' => 'Critical Defect',
                'message' => "{$defect->apparatus?->unit_id ?? 'Unknown'}: {$defect->item}",
                'details' => $defect->notes,
                'time' => $defect->created_at?->diffForHumans() ?? 'Unknown',
            ];
        }

        // Very low stock items (critical)
        try {
            $criticalStock = EquipmentItem::where('is_active', true)
                ->whereRaw('stock <= reorder_min / 2')
                ->limit(5)
                ->get();

            foreach ($criticalStock as $item) {
                $alerts[] = [
                    'type' => 'warning',
                    'icon' => 'heroicon-o-exclamation-circle',
                    'title' => 'Critical Low Stock',
                    'message' => "{$item->name} ({$item->stock}/{$item->reorder_min})",
                    'details' => "Category: {$item->category}",
                    'time' => $item->updated_at?->diffForHumans() ?? 'Unknown',
                ];
            }
        } catch (\Exception $e) {
            // EquipmentItem table may not exist
        }

        $this->alerts = $alerts;
    }

    public function refresh(): void
    {
        $this->loadAlerts();
    }
}
