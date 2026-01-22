<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Services\CloudflareAIService;
use App\Models\CapitalProject;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ShopWork;
use App\Models\EquipmentItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class SmartUpdatesWidget extends Widget
{
    protected static string $view = 'filament.widgets.smart-updates-widget';

    protected int | string | array $columnSpan = 'full';

    // Instant data - no AI delay
    public ?array $bulletSummary = null;
    public ?array $rawMetrics = null;

    // Chat state - always visible
    public string $chatInput = '';
    public array $chatMessages = [];
    public bool $chatLoading = false;

    public function mount(): void
    {
        // Instant load from database - NO AI call
        $this->loadInstantSummary();
    }

    /**
     * Load instant bullet summary from database (no AI)
     */
    public function loadInstantSummary(): void
    {
        $this->rawMetrics = $this->gatherOperationalMetrics();
        $this->bulletSummary = $this->generateBulletSummary($this->rawMetrics);
    }

    /**
     * Generate bullet summary directly from metrics (instant)
     */
    protected function generateBulletSummary(array $metrics): array
    {
        $bullets = [];

        // Out of Service Vehicles (CRITICAL - show first)
        if (!empty($metrics['out_of_service'])) {
            $bullets['out_of_service'] = [
                'icon' => '🚨',
                'title' => 'Out of Service',
                'color' => 'red',
                'items' => array_map(fn($v) => "{$v['unit']} - {$v['vehicle']}" . ($v['notes'] ? " ({$v['notes']})" : ''), $metrics['out_of_service']),
            ];
        }

        // Open Defects
        if (!empty($metrics['apparatus_issues'])) {
            $bullets['defects'] = [
                'icon' => '🔧',
                'title' => 'Open Defects',
                'color' => 'orange',
                'items' => array_map(fn($d) => "{$d['unit']}: {$d['issue']}", array_slice($metrics['apparatus_issues'], 0, 5)),
            ];
        }

        // Low Stock Equipment
        if (!empty($metrics['equipment_inventory']['low_stock_items'])) {
            $bullets['low_stock'] = [
                'icon' => '📦',
                'title' => 'Low Stock Items',
                'color' => 'yellow',
                'items' => array_map(fn($i) => "{$i['name']} ({$i['current_stock']}/{$i['reorder_min']})", $metrics['equipment_inventory']['low_stock_items']),
            ];
        }

        // Active Shop Work
        if (!empty($metrics['shop_work'])) {
            $bullets['shop_work'] = [
                'icon' => '🛠️',
                'title' => 'Active Shop Work',
                'color' => 'purple',
                'items' => array_map(fn($w) => "{$w['project']} - {$w['status']}", array_slice($metrics['shop_work'], 0, 5)),
            ];
        }

        // Capital Projects (overdue/at risk)
        $projectAlerts = [];
        if (($metrics['capital_projects']['overdue'] ?? 0) > 0) {
            $projectAlerts[] = "{$metrics['capital_projects']['overdue']} overdue project(s)";
        }
        if (($metrics['capital_projects']['at_risk'] ?? 0) > 0) {
            $projectAlerts[] = "{$metrics['capital_projects']['at_risk']} at-risk project(s)";
        }
        if (!empty($projectAlerts)) {
            $bullets['projects'] = [
                'icon' => '📋',
                'title' => 'Project Alerts',
                'color' => 'blue',
                'items' => $projectAlerts,
            ];
        }

        // Fleet summary
        $bullets['fleet'] = [
            'icon' => '🚒',
            'title' => 'Fleet Status',
            'color' => 'green',
            'items' => [
                "{$metrics['vehicle_inventory']['total']} total apparatus",
                ($metrics['vehicle_inventory']['by_status']['In Service'] ?? 0) . " in service",
            ],
        ];

        return $bullets;
    }

    /**
     * Send chat message to AI
     */
    public function sendChat(): void
    {
        if (empty(trim($this->chatInput))) {
            return;
        }

        $userMessage = trim($this->chatInput);
        $this->chatInput = '';
        $this->chatLoading = true;

        $this->chatMessages[] = [
            'role' => 'user',
            'content' => $userMessage,
            'time' => now()->format('g:i A'),
        ];

        try {
            $aiService = app(CloudflareAIService::class);
            
            if (!$aiService->isEnabled()) {
                $this->chatMessages[] = [
                    'role' => 'assistant',
                    'content' => 'AI service is not configured.',
                    'time' => now()->format('g:i A'),
                ];
            } else {
                $response = $aiService->chat($userMessage, $this->rawMetrics ?? []);
                
                $this->chatMessages[] = [
                    'role' => 'assistant',
                    'content' => $response['message'],
                    'time' => now()->format('g:i A'),
                ];
            }
        } catch (\Exception $e) {
            $this->chatMessages[] = [
                'role' => 'assistant',
                'content' => 'Error: ' . $e->getMessage(),
                'time' => now()->format('g:i A'),
            ];
        }

        $this->chatLoading = false;
    }

    public function refresh(): void
    {
        $this->loadInstantSummary();
    }

    #[On('equipment-updated')]
    #[On('project-updated')]
    #[On('apparatus-updated')]
    public function handleDataChange(): void
    {
        $this->loadInstantSummary();
    }

    /**
     * Gather operational metrics from all relevant models
     */
    protected function gatherOperationalMetrics(): array
    {
        // Vehicle inventory
        $totalApparatus = Apparatus::count();
        $apparatusByStatus = Apparatus::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        
        // Out of service vehicles
        $outOfService = Apparatus::where('status', 'Out of Service')
            ->orWhere('status', 'out_of_service')
            ->get(['unit_id', 'make', 'model', 'notes']);
        
        // Open defects/apparatus issues
        $openDefects = ApparatusDefect::where('resolved', false)
            ->with('apparatus:id,unit_id')
            ->limit(10)
            ->get(['id', 'apparatus_id', 'item', 'status', 'notes', 'created_at']);
        
        // Shop work (active projects)
        $activeShopWork = [];
        try {
            $activeShopWork = ShopWork::whereIn('status', ['Pending', 'In Progress', 'Waiting for Parts'])
                ->with('apparatus:id,unit_id')
                ->limit(10)
                ->get(['id', 'project_name', 'status', 'apparatus_id', 'started_date']);
        } catch (\Exception $e) {
            // ShopWork table may not exist
        }
        
        // Equipment inventory
        $equipmentData = [];
        try {
            $totalEquipment = EquipmentItem::where('is_active', true)->count();
            $allEquipment = EquipmentItem::where('is_active', true)->get();
            
            // Calculate low stock items (stock <= reorder_min)
            $lowStockItems = $allEquipment->filter(fn($item) => $item->stock <= $item->reorder_min);
            
            // Group by category
            $byCategory = $allEquipment->groupBy('category')->map->count()->toArray();
            
            $equipmentData = [
                'total_items' => $totalEquipment,
                'low_stock_count' => $lowStockItems->count(),
                'by_category' => $byCategory,
                'low_stock_items' => $lowStockItems->take(5)->map(fn($item) => [
                    'name' => $item->name,
                    'current_stock' => $item->stock,
                    'reorder_min' => $item->reorder_min,
                    'category' => $item->category,
                ])->values()->toArray(),
            ];
        } catch (\Exception $e) {
            // EquipmentItem table may not exist
            Log::debug('Equipment metrics unavailable: ' . $e->getMessage());
        }
        
        // Capital projects
        $capitalProjects = CapitalProject::with(['milestones', 'updates'])
            ->orderBy('priority')
            ->limit(10)
            ->get();
        
        $overdueProjects = $capitalProjects->filter(fn($p) => $p->is_overdue ?? false);
        $atRiskProjects = $capitalProjects->filter(fn($p) => 
            ($p->status->value ?? $p->status) === 'at_risk'
        );

        return [
            'vehicle_inventory' => [
                'total' => $totalApparatus,
                'by_status' => $apparatusByStatus,
            ],
            'out_of_service' => $outOfService->map(fn($a) => [
                'unit' => $a->unit_id,
                'vehicle' => "{$a->make} {$a->model}",
                'notes' => $a->notes,
            ])->toArray(),
            'apparatus_issues' => $openDefects->map(fn($d) => [
                'unit' => $d->apparatus?->unit_id ?? 'Unknown',
                'issue' => $d->item,
                'severity' => $d->status,
            ])->toArray(),
            'shop_work' => collect($activeShopWork)->map(fn($w) => [
                'project' => $w->project_name,
                'status' => $w->status,
                'unit' => $w->apparatus?->unit_id ?? 'N/A',
            ])->toArray(),
            'equipment_inventory' => $equipmentData,
            'capital_projects' => [
                'total' => $capitalProjects->count(),
                'overdue' => $overdueProjects->count(),
                'at_risk' => $atRiskProjects->count(),
                'recent' => $capitalProjects->take(5)->map(fn($p) => [
                    'name' => $p->name,
                    'status' => $p->status->value ?? $p->status,
                    'priority' => $p->priority->value ?? $p->priority,
                ])->toArray(),
            ],
        ];
    }

}
