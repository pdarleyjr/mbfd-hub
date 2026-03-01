<?php

namespace App\Filament\Widgets\Admin;

use App\Models\Apparatus;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class FleetSnapshotWidget extends Widget
{
    protected static string $view = 'filament.widgets.admin.fleet-snapshot-widget';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'lg' => 3,
    ];

    public ?array $fleetData = null;

    public function mount(): void
    {
        $this->loadFleetData();
    }

    public function loadFleetData(): void
    {
        $statusCounts = Apparatus::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total = array_sum($statusCounts);
        
        $inService = $statusCounts['In Service'] ?? $statusCounts['in_service'] ?? 0;
        $outOfService = $statusCounts['Out of Service'] ?? $statusCounts['out_of_service'] ?? 0;
        $maintenance = $statusCounts['Maintenance'] ?? $statusCounts['maintenance'] ?? 0;
        $reserved = $statusCounts['Reserved'] ?? $statusCounts['reserved'] ?? 0;

        $this->fleetData = [
            'total' => $total,
            'in_service' => $inService,
            'out_of_service' => $outOfService,
            'maintenance' => $maintenance,
            'reserved' => $reserved,
            'in_service_pct' => $total > 0 ? round(($inService / $total) * 100) : 0,
            'out_of_service_pct' => $total > 0 ? round(($outOfService / $total) * 100) : 0,
            'maintenance_pct' => $total > 0 ? round(($maintenance / $total) * 100) : 0,
        ];
    }

    public function refresh(): void
    {
        $this->loadFleetData();
    }
}
