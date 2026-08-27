<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupSession;
use App\Services\Workgroup\EvaluationService;
use App\Services\Workgroup\WorkgroupAIService;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Session Results — comprehensive evaluation results dashboard.
 *
 * All data is fetched inline via getViewData() and rendered as plain Blade.
 * No child Livewire widgets — avoids ERROR-018 stale-state on session switch.
 * AI executive report loads asynchronously via wire:init to prevent page-load lag.
 */
class SessionResultsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $title = 'Session Results';

    protected static string $view = 'filament.workgroup.pages.session-results';

    protected static ?string $navigationLabel = 'Results';

    protected static ?string $slug = 'session-results';

    protected static ?int $navigationSort = 5;

    public ?int $selectedSessionId = null;

    /** Async AI report state */
    public bool $aiReportLoaded = false;

    public ?string $aiReport = null;

    public ?string $aiReportError = null;

    /** SAVER Report state */
    public bool $saverReportLoading = false;

    public ?string $saverReportHtml = null;

    public ?string $saverReportError = null;

    /**
     * Export a specific granular tool table as CSV.
     * Keys: cutoff_saws, spreaders, cutters, rams, t1_standalone, brand_overall
     */
    public function exportTableCsv(string $tableKey)
    {
        $sessionId = $this->requireSelectedSession()->id;
        $evalService = app(EvaluationService::class);
        $granular = $evalService->getGranularToolGroupings($sessionId);

        $filename = $tableKey.'_results_'.now()->format('Y-m-d').'.csv';

        if ($tableKey === 't1_standalone') {
            $t1 = $granular['t1_standalone'] ?? null;

            return response()->streamDownload(function () use ($t1) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Product', 'Brand', 'Overall Score', 'Capability', 'Usability', 'Affordability', 'Maintainability', 'Deployability', 'Responses']);
                if ($t1) {
                    fputcsv($handle, [
                        $t1['name'] ?? '',
                        $t1['brand'] ?? '',
                        $t1['avg_score'] ?? '',
                        $t1['saver_breakdown']['capability'] ?? '',
                        $t1['saver_breakdown']['usability'] ?? '',
                        $t1['saver_breakdown']['affordability'] ?? '',
                        $t1['saver_breakdown']['maintainability'] ?? '',
                        $t1['saver_breakdown']['deployability'] ?? '',
                        $t1['response_count'] ?? '',
                    ]);
                }
                fclose($handle);
            }, $filename, ['Content-Type' => 'text/csv']);
        }

        if ($tableKey === 'brand_overall') {
            $brands = $granular['brand_overall'] ?? [];

            return response()->streamDownload(function () use ($brands) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Rank', 'Brand', 'Overall Avg', 'Tool Count', 'Capability', 'Usability', 'Affordability', 'Maintainability', 'Deployability']);
                foreach ($brands as $b) {
                    fputcsv($handle, [
                        $b['rank'] ?? '',
                        $b['brand'] ?? '',
                        $b['overall_avg'] ?? '',
                        $b['tool_count'] ?? '',
                        $b['saver_breakdown']['capability'] ?? '',
                        $b['saver_breakdown']['usability'] ?? '',
                        $b['saver_breakdown']['affordability'] ?? '',
                        $b['saver_breakdown']['maintainability'] ?? '',
                        $b['saver_breakdown']['deployability'] ?? '',
                    ]);
                }
                fclose($handle);
            }, $filename, ['Content-Type' => 'text/csv']);
        }

        // Standard product tables: cutoff_saws, spreaders, cutters, rams
        $items = $granular[$tableKey] ?? [];

        return response()->streamDownload(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Rank', 'Product', 'Brand', 'Overall Score', 'Capability', 'Usability', 'Affordability', 'Maintainability', 'Deployability', 'Advance Yes', 'Advance No', 'Deal Breakers', 'Responses']);
            foreach ($items as $idx => $item) {
                fputcsv($handle, [
                    $idx + 1,
                    $item['name'] ?? ($item['product']->name ?? ''),
                    $item['brand'] ?? '',
                    $item['avg_score'] ?? '',
                    $item['capability_avg'] ?? '',
                    $item['usability_avg'] ?? '',
                    $item['affordability_avg'] ?? '',
                    $item['maintainability_avg'] ?? '',
                    $item['deployability_avg'] ?? '',
                    $item['advance_yes'] ?? '',
                    $item['advance_no'] ?? '',
                    $item['deal_breakers'] ?? '',
                    $item['response_count'] ?? '',
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Export category rankings table as CSV.
     */
    public function exportCategoryRankingsCsv(string $categoryName)
    {
        $session = $this->requireSelectedSession();
        $evalService = app(EvaluationService::class);
        $sessionId = $session->id;
        $results = $evalService->getSessionResults($sessionId);

        $filename = str_replace(' ', '_', strtolower($categoryName)).'_rankings_'.now()->format('Y-m-d').'.csv';

        $targetCat = collect($results['rankable_categories'])->first(fn ($c) => $c['category_name'] === $categoryName);

        return response()->streamDownload(function () use ($targetCat) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Rank', 'Product', 'Manufacturer', 'Model', 'Overall Score', 'Responses', 'Meets Threshold']);
            if ($targetCat) {
                foreach ($targetCat['rankings'] as $idx => $item) {
                    fputcsv($handle, [
                        $idx + 1,
                        $item['product']->name ?? '',
                        $item['product']->manufacturer ?? '',
                        $item['product']->model ?? '',
                        $item['weighted_average'] ?? '',
                        $item['response_count'] ?? '',
                        $item['meets_threshold'] ? 'Yes' : 'No',
                    ]);
                }
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Export competitor group rankings as CSV.
     */
    public function exportCompetitorGroupsCsv()
    {
        $session = $this->requireSelectedSession();
        $evalService = app(EvaluationService::class);
        $workgroup = $session->workgroup;

        $filename = 'competitor_group_rankings_'.now()->format('Y-m-d').'.csv';

        $rankings = $workgroup instanceof Workgroup
            ? $evalService->getCompetitorGroupRankings($workgroup, $session)
            : [];

        return response()->streamDownload(function () use ($rankings) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Category', 'Group', 'Rank', 'Product', 'Brand', 'Avg Score', 'Responses']);
            foreach ($rankings as $cat) {
                foreach ($cat['groups'] as $group) {
                    foreach ($group['rankings'] as $idx => $r) {
                        fputcsv($handle, [
                            $cat['category_name'],
                            $group['group_name'],
                            $idx + 1,
                            $r['name'] ?? '',
                            $r['brand'] ?? '',
                            $r['avg_score'] ?? '',
                            $r['response_count'] ?? '',
                        ]);
                    }
                }
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Export finalists summary as CSV.
     */
    public function exportFinalistsCsv()
    {
        $session = $this->requireSelectedSession();
        $evalService = app(EvaluationService::class);
        $sessionId = $session->id;
        $results = $evalService->getSessionResults($sessionId);

        $filename = 'finalists_'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($results) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Category', 'Rank', 'Product', 'Manufacturer', 'Avg Score', 'Responses']);
            foreach ($results['rankable_categories'] as $cat) {
                $rankings = collect($cat['rankings'])->filter(fn ($r) => $r['meets_threshold'])->take(2);
                foreach ($rankings as $idx => $item) {
                    fputcsv($handle, [
                        $cat['category_name'],
                        $idx + 1,
                        $item['product']->name ?? '',
                        $item['product']->manufacturer ?? '',
                        $item['weighted_average'] ?? '',
                        $item['response_count'] ?? '',
                    ]);
                }
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function getHeading(): string
    {
        $session = $this->getSelectedSession();

        return $session ? "Results: {$session->name}" : 'Session Results';
    }

    public function getSubheading(): ?string
    {
        $session = $this->requireSelectedSession();

        $progress = app(EvaluationService::class)->getSessionProgress($session->id);

        return "Completion: {$progress['completion_percentage']}% — {$progress['submitted_submissions']} of {$progress['max_possible_submissions']} evaluations submitted";
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 404);

        $session = $this->scopedSessions()->active()->orderBy('id')->first()
            ?? $this->scopedSessions()->orderBy('id')->first();

        abort_unless($session !== null, 404);

        $this->selectedSessionId = $session->id;
    }

    public function getSelectedSession(): ?WorkgroupSession
    {
        if ($this->selectedSessionId === null) {
            return null;
        }

        return $this->scopedSessions()
            ->with('workgroup')
            ->find($this->selectedSessionId);
    }

    /**
     * All available sessions for the blade session switcher pills.
     */
    public function getAllSessions(): \Illuminate\Support\Collection
    {
        return $this->scopedSessions()->orderBy('name')->get();
    }

    public function updatingSelectedSessionId(?int $sessionId): void
    {
        $this->assertSessionIsAccessible($sessionId);
    }

    /**
     * Livewire method to switch session from the Blade dropdown.
     */
    public function switchSession(?int $sessionId): void
    {
        $this->assertSessionIsAccessible($sessionId);

        $this->selectedSessionId = $sessionId;
        // Clear AI and SAVER report when switching sessions
        $this->aiReportLoaded = false;
        $this->aiReport = null;
        $this->aiReportError = null;
        $this->saverReportHtml = null;
        $this->saverReportError = null;
        $this->saverReportLoading = false;
    }

    /**
     * Load AI Executive Report asynchronously (called via wire:init).
     */
    public function loadAiReport(): void
    {
        $session = $this->requireSelectedSession();
        app(WorkgroupAccess::class)->requireManageSession($this->currentUser(), $session);

        try {
            $aiService = app(WorkgroupAIService::class);
            $workgroup = $session->workgroup;

            if (! ($workgroup instanceof Workgroup)) {
                $this->aiReportError = 'No workgroup found for AI report generation.';
                $this->aiReportLoaded = true;

                return;
            }

            $result = $aiService->generateExecutiveReport($workgroup, $session);
            $this->aiReport = is_array($result) ? ($result['report'] ?? json_encode($result)) : (string) $result;
            $this->aiReportLoaded = true;
        } catch (\Throwable $exception) {
            Log::error('[WorkgroupAI] Worker request failed', [
                'operation' => 'session_results_executive_report',
                'exception_type' => $exception::class,
            ]);
            $this->aiReportError = 'AI service request failed. Please try again later.';
            $this->aiReportLoaded = true;
        }
    }

    /**
     * Regenerate AI Executive Report (force refresh).
     */
    public function regenerateAiReport(): void
    {
        $this->aiReportLoaded = false;
        $this->aiReport = null;
        $this->aiReportError = null;
        $this->loadAiReport();
    }

    /**
     * Generate a session-scoped SAVER Executive Report via AI.
     */
    public function generateSaverReport(): void
    {
        $session = $this->requireSelectedSession();
        app(WorkgroupAccess::class)->requireManageSession($this->currentUser(), $session);

        $this->saverReportLoading = true;
        $this->saverReportError = null;
        $this->saverReportHtml = null;

        try {
            $workgroup = $session->workgroup;
            if (! ($workgroup instanceof Workgroup)) {
                $this->saverReportError = 'No workgroup found.';
                $this->saverReportLoading = false;

                return;
            }

            $aiService = app(WorkgroupAIService::class);
            $html = $aiService->generateSaverReport($workgroup, $session);

            $this->saverReportHtml = $html;
        } catch (\Throwable $exception) {
            Log::error('[WorkgroupAI] Worker request failed', [
                'operation' => 'session_results_saver_report',
                'exception_type' => $exception::class,
            ]);
            $this->saverReportError = 'AI service request failed. Please try again later.';
        } finally {
            $this->saverReportLoading = false;
        }
    }

    // ─── Header Widgets ─────────────────────────────────────────────
    protected function getHeaderWidgets(): array
    {
        // Progress stats are rendered directly in the blade from $progress
        // to avoid Livewire child component stale-state issues when switching sessions.
        // The $progress data is included in getViewData() which is always fresh.
        return [];
    }

    // ─── Footer Widgets (rankings table) ────────────────────────────
    protected function getFooterWidgets(): array
    {
        // Finalists data is now rendered directly in the blade from $categoryResults
        // to avoid Livewire child component stale-state issues when switching sessions.
        return [];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getFooterWidgetsColumns(): int|string|array
    {
        return 1;
    }

    // ─── Header Actions ─────────────────────────────────────────────
    protected function getHeaderActions(): array
    {
        // Session switching is now handled by inline pill navigation in the blade view
        // (wire:click calls switchSession() Livewire method directly)
        return [];
    }

    // ─── View Data (passed to blade) ────────────────────────────────
    protected function getViewData(): array
    {
        $session = $this->requireSelectedSession();
        $evalService = app(EvaluationService::class);
        $allSessions = $this->getAllSessions();
        $workgroup = $session->workgroup;
        $sessionId = $session->id;

        $results = $evalService->getSessionResults($sessionId);
        $progress = $evalService->getSessionProgress($sessionId);

        // Get comprehensive results for the overall view
        $comprehensiveResults = [];
        $competitorGroupRankings = [];
        $isolatedProducts = [];
        $nonRankableFeedback = collect();

        if ($workgroup instanceof Workgroup) {
            $comprehensiveResults = $evalService->getComprehensiveResults($workgroup, $session);
            $competitorGroupRankings = $comprehensiveResults['competitor_group_rankings'] ?? [];
            $isolatedProducts = $comprehensiveResults['isolated_products'] ?? [];
            $nonRankableFeedback = $comprehensiveResults['non_rankable_feedback'] ?? collect();
        }

        // Enhance category results with SAVER score breakdown
        $categoryResults = collect($results['rankable_categories'])->map(function ($cat) {
            $rankings = collect($cat['rankings'])->map(function ($item) {
                $product = $item['product'];
                $submissions = \App\Models\EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')
                    ->whereHas('member', fn ($q) => $q->where('count_evaluations', true))
                    ->get();

                return array_merge($item, [
                    'capability_avg' => $submissions->avg('capability_score'),
                    'usability_avg' => $submissions->avg('usability_score'),
                    'affordability_avg' => $submissions->avg('affordability_score'),
                    'maintainability_avg' => $submissions->avg('maintainability_score'),
                    'deployability_avg' => $submissions->avg('deployability_score'),
                    'advance_yes' => $submissions->where('advance_recommendation', 'yes')->count(),
                    'advance_no' => $submissions->where('advance_recommendation', 'no')->count(),
                    'deal_breakers' => $submissions->where('has_deal_breaker', true)->count(),
                ]);
            });

            return array_merge($cat, ['rankings' => $rankings]);
        });

        return [
            'session' => $session,
            'categoryResults' => $categoryResults,
            'progress' => $progress,
            'sessions' => $allSessions,
            'brandGroupedAnalysis' => $evalService->getBrandGroupedAnalysis($sessionId),
            'workgroup' => $workgroup,
            'comprehensiveResults' => $comprehensiveResults,
            'competitorGroupRankings' => $competitorGroupRankings,
            'isolatedProducts' => $isolatedProducts,
            'nonRankableFeedback' => $nonRankableFeedback instanceof \Illuminate\Support\Collection ? $nonRankableFeedback : collect($nonRankableFeedback),
            'granularToolGroupings' => $evalService->getGranularToolGroupings($sessionId),
        ];
    }

    // ─── Access Control ─────────────────────────────────────────────
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(WorkgroupAccess::class)->canEnterPanel($user);
    }

    public function canManageSelectedSession(): bool
    {
        $session = $this->getSelectedSession();

        return $session !== null
            && app(WorkgroupAccess::class)->canManageSession($this->currentUser(), $session);
    }

    private function assertSessionIsAccessible(?int $sessionId): void
    {
        abort_unless(
            $sessionId !== null && $this->scopedSessions()->whereKey($sessionId)->exists(),
            404,
        );
    }

    private function requireSelectedSession(): WorkgroupSession
    {
        $session = $this->getSelectedSession();

        abort_unless($session !== null, 404);

        return $session;
    }

    /** @return Builder<WorkgroupSession> */
    private function scopedSessions(): Builder
    {
        $user = $this->currentUser();
        $workgroup = app(WorkgroupContext::class)->requireCurrent($user);

        return app(WorkgroupAccess::class)
            ->scopeSessions(WorkgroupSession::query(), $user)
            ->where('workgroup_id', $workgroup->id);
    }

    private function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 404);

        return $user;
    }
}
