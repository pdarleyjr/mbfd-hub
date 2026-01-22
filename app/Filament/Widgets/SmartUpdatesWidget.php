<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Services\CloudflareAIService;
use App\Models\CapitalProject;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ShopWork;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SmartUpdatesWidget extends Widget
{
    protected static string $view = 'filament.widgets.smart-updates-widget';

    protected int | string | array $columnSpan = 'full';

    public ?array $smartUpdateData = null;
    public bool $isLoading = true;

    public function mount(): void
    {
        $this->loadSmartUpdates();
    }

    public function loadSmartUpdates(): void
    {
        $this->isLoading = true;
        
        try {
            $aiService = app(CloudflareAIService::class);
            
            if (!$aiService->isEnabled()) {
                $this->smartUpdateData = [
                    'error' => 'AI service not configured',
                    'bullets' => $this->generateFallbackBullets(),
                    'generated_at' => now()->toIso8601String(),
                ];
                $this->isLoading = false;
                return;
            }

            // Gather real-time metrics from all relevant models
            $metrics = $this->gatherOperationalMetrics();

            // Generate AI-powered bullet summary
            $bullets = $aiService->generateAdminBulletSummary($metrics);

            $this->smartUpdateData = [
                'bullets' => $bullets,
                'metrics' => $metrics,
                'generated_at' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('SmartUpdatesWidget error', ['message' => $e->getMessage()]);
            $this->smartUpdateData = [
                'error' => $e->getMessage(),
                'bullets' => $this->generateFallbackBullets(),
                'generated_at' => now()->toIso8601String(),
            ];
        }
        
        $this->isLoading = false;
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

    /**
     * Generate fallback bullets when AI is unavailable
     */
    protected function generateFallbackBullets(): array
    {
        $metrics = $this->gatherOperationalMetrics();
        
        return [
            'vehicle_inventory' => [
                "{$metrics['vehicle_inventory']['total']} total apparatus in fleet",
            ],
            'out_of_service' => count($metrics['out_of_service']) > 0 
                ? array_map(fn($v) => "{$v['unit']} - {$v['vehicle']}", array_slice($metrics['out_of_service'], 0, 3))
                : ['All vehicles operational'],
            'apparatus_issues' => count($metrics['apparatus_issues']) > 0
                ? array_map(fn($i) => "{$i['unit']}: {$i['issue']}", array_slice($metrics['apparatus_issues'], 0, 3))
                : ['No open defects reported'],
            'equipment_alerts' => count($metrics['shop_work']) > 0
                ? array_map(fn($w) => "{$w['project']} ({$w['status']})", array_slice($metrics['shop_work'], 0, 3))
                : ['No active shop work'],
            'capital_projects' => [
                "{$metrics['capital_projects']['total']} active projects",
                $metrics['capital_projects']['overdue'] > 0 
                    ? "{$metrics['capital_projects']['overdue']} overdue" 
                    : "All projects on schedule",
            ],
        ];
    }

    public function refresh(): void
    {
        $this->loadSmartUpdates();
    }
}
