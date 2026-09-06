<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateCommandCenterSummaryJob;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\CapitalProject;
use App\Models\EquipmentItem;
use App\Models\ShopWork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Command Center AI brief.
 *
 * Gathers operational metrics (shared by the dashboard widget and the
 * background job) and manages a cached AI summary that is regenerated ONLY
 * when the underlying data changes — detected via a fingerprint of the
 * metrics. This keeps gateway-routed generation idle unless
 * there is genuinely new information to summarize.
 *
 * Flow: the widget polls every ~2 min and calls ensureFresh(), which is
 * non-blocking — it dispatches GenerateCommandCenterSummaryJob to the queue
 * only when the fingerprint changed and no regeneration is already pending.
 */
class CommandCenterAiService
{
    public const CACHE_KEY = 'command_center_ai_summary';   // ['fp','summary','at']
    public const PENDING_KEY = 'command_center_ai_pending'; // fingerprint currently being generated
    public const CACHE_TTL = 86400;                          // 24h
    public const PENDING_TTL = 600;                          // 10m guard against stuck jobs

    /**
     * A stable hash of the change-relevant operational data. Any change to
     * fleet status, defects, shop work, low stock, or project risk flips it.
     */
    public function fingerprint(array $metrics): string
    {
        return md5((string) json_encode($metrics));
    }

    /**
     * @return array{fp: string, summary: array, at: string}|null
     */
    public function cachedSummary(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Non-blocking freshness check. Dispatches a regeneration job to the queue
     * only when data changed since the last cached summary AND no job is
     * already pending for the current fingerprint.
     */
    public function ensureFresh(?array $metrics = null): void
    {
        try {
            $metrics ??= $this->gatherMetrics();
            $fp = $this->fingerprint($metrics);

            $cached = $this->cachedSummary();
            if ($cached && ($cached['fp'] ?? null) === $fp) {
                return; // already current
            }
            if (Cache::get(self::PENDING_KEY) === $fp) {
                return; // a job for this exact state is already queued/running
            }

            Cache::put(self::PENDING_KEY, $fp, self::PENDING_TTL);
            GenerateCommandCenterSummaryJob::dispatch($fp);
        } catch (\Throwable $e) {
            Log::debug('[CommandCenter] ensureFresh skipped: ' . $e->getMessage());
        }
    }

    /**
     * Gather operational metrics from the relevant models. (Single source of
     * truth — used by the widget for instant bullets and by the AI job.)
     */
    public function gatherMetrics(): array
    {
        $totalApparatus = Apparatus::count();
        $apparatusByStatus = Apparatus::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $outOfService = Apparatus::where('status', 'Out of Service')
            ->orWhere('status', 'out_of_service')
            ->get(['unit_id', 'make', 'model', 'notes']);

        $overdueInspections = Apparatus::whereDoesntHave('inspections', function ($q) {
            $q->where('created_at', '>=', today()->subDay());
        })->count();

        $openDefects = ApparatusDefect::where('resolved', false)
            ->with('apparatus:id,unit_id')
            ->limit(10)
            ->get(['id', 'apparatus_id', 'item', 'status', 'notes', 'created_at']);

        $activeShopWork = [];
        try {
            $activeShopWork = ShopWork::whereIn('status', ['Pending', 'In Progress', 'Waiting for Parts'])
                ->with('apparatus:id,unit_id')
                ->limit(10)
                ->get(['id', 'project_name', 'status', 'apparatus_id', 'started_date']);
        } catch (\Exception $e) {
            // ShopWork table may not exist
        }

        $equipmentData = [];
        try {
            $totalEquipment = EquipmentItem::where('is_active', true)->count();
            $allEquipment = EquipmentItem::where('is_active', true)->get();
            $lowStockItems = $allEquipment->filter(fn ($item) => $item->stock <= $item->reorder_min);
            $byCategory = $allEquipment->groupBy('category')->map->count()->toArray();

            $equipmentData = [
                'total_items' => $totalEquipment,
                'low_stock_count' => $lowStockItems->count(),
                'by_category' => $byCategory,
                'low_stock_items' => $lowStockItems->take(5)->map(fn ($item) => [
                    'name' => $item->name,
                    'current_stock' => $item->stock,
                    'reorder_min' => $item->reorder_min,
                    'category' => $item->category,
                ])->values()->toArray(),
            ];
        } catch (\Exception $e) {
            Log::debug('Equipment metrics unavailable: ' . $e->getMessage());
        }

        $capitalProjects = CapitalProject::with(['milestones', 'updates'])
            ->orderBy('priority')
            ->limit(10)
            ->get();

        $overdueProjects = $capitalProjects->filter(fn ($p) => $p->is_overdue ?? false);
        $atRiskProjects = $capitalProjects->filter(fn ($p) => ($p->status->value ?? $p->status) === 'at_risk');

        return [
            'vehicle_inventory' => [
                'total' => $totalApparatus,
                'by_status' => $apparatusByStatus,
                'overdue_inspections' => $overdueInspections,
            ],
            'out_of_service' => $outOfService->map(fn ($a) => [
                'unit' => $a->unit_id,
                'vehicle' => "{$a->make} {$a->model}",
                'notes' => $a->notes,
            ])->toArray(),
            'apparatus_issues' => $openDefects->map(fn ($d) => [
                'unit' => $d->apparatus?->unit_id ?? 'Unknown',
                'issue' => $d->item,
                'severity' => $d->status,
            ])->toArray(),
            'shop_work' => collect($activeShopWork)->map(fn ($w) => [
                'project' => $w->project_name,
                'status' => $w->status,
                'unit' => $w->apparatus?->unit_id ?? 'N/A',
            ])->toArray(),
            'equipment_inventory' => $equipmentData,
            'capital_projects' => [
                'total' => $capitalProjects->count(),
                'overdue' => $overdueProjects->count(),
                'at_risk' => $atRiskProjects->count(),
                'recent' => $capitalProjects->take(5)->map(fn ($p) => [
                    'name' => $p->name,
                    'status' => $p->status->value ?? $p->status,
                    'priority' => $p->priority->value ?? $p->priority,
                ])->toArray(),
            ],
        ];
    }
}
