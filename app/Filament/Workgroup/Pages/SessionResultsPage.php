<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Widgets\CategoryRankingsWidget;
use App\Filament\Workgroup\Widgets\FinalistsWidget;
use App\Filament\Workgroup\Widgets\SessionProgressWidget;
use App\Models\EvaluationSubmission;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Services\Workgroup\EvaluationService;
use App\Services\Workgroup\WorkgroupAIService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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

    public function getHeading(): string
    {
        $session = $this->getSelectedSession();
        if (!$session && $this->selectedSessionId === null) {
            return 'Overall Results — All Sessions';
        }
        return $session ? "Results: {$session->name}" : 'Session Results';
    }

    public function getSubheading(): ?string
    {
        $session = $this->getSelectedSession();
        if (!$session) {
            $allSessions = WorkgroupSession::all();
            return "Showing combined results across all {$allSessions->count()} session(s).";
        }

        $progress = app(EvaluationService::class)->getSessionProgress($session->id);
        return "Completion: {$progress['completion_percentage']}% — {$progress['submitted_submissions']} of {$progress['max_possible_submissions']} evaluations submitted";
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        // Default to null (Overall Results — all sessions combined)
        $this->selectedSessionId = null;
    }

    public function getSelectedSession(): ?WorkgroupSession
    {
        if ($this->selectedSessionId) {
            return WorkgroupSession::find($this->selectedSessionId);
        }
        return null; // null = Overall / All Sessions
    }

    /**
     * All available sessions for the blade session switcher pills.
     */
    public function getAllSessions(): \Illuminate\Support\Collection
    {
        return WorkgroupSession::orderBy('name')->get();
    }

    /**
     * Livewire method to switch session from the Blade dropdown.
     */
    public function switchSession(?int $sessionId): void
    {
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
        try {
            $session = $this->getSelectedSession();

            $aiService = app(WorkgroupAIService::class);
            $workgroup = Workgroup::first();

            if (!$workgroup) {
                $this->aiReportError = 'No workgroup found for AI report generation.';
                $this->aiReportLoaded = true;
                return;
            }

            // When session is null, this is the "Overall" scope — generate
            // an aggregate report across ALL sessions, not just Day 1.
            $result = $aiService->generateExecutiveReport($workgroup, $session);
            $this->aiReport = is_array($result) ? ($result['report'] ?? json_encode($result)) : (string) $result;
            $this->aiReportLoaded = true;
        } catch (\Exception $e) {
            Log::error('[SessionResultsPage] AI report failed', ['error' => $e->getMessage()]);
            $this->aiReportError = 'Failed to generate AI report: ' . $e->getMessage();
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
     * Generate SAVER Executive Report via AI.
     * Only available on the "Overall" view (selectedSessionId === null).
     */
    public function generateSaverReport(): void
    {
        $this->saverReportLoading = true;
        $this->saverReportError = null;
        $this->saverReportHtml = null;

        try {
            $workgroup = Workgroup::first();
            if (!$workgroup) {
                $this->saverReportError = 'No workgroup found.';
                $this->saverReportLoading = false;
                return;
            }

            $session = $this->getSelectedSession();
            $aiService = app(WorkgroupAIService::class);
            $html = $aiService->generateSaverReport($workgroup, $session);

            $this->saverReportHtml = $html;
        } catch (\Exception $e) {
            Log::error('[SessionResultsPage] SAVER report failed', ['error' => $e->getMessage()]);
            $this->saverReportError = 'Failed to generate report: ' . $e->getMessage();
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
        $session = $this->getSelectedSession();
        $evalService = app(EvaluationService::class);
        $allSessions = WorkgroupSession::orderBy('name')->get();
        $workgroup = Workgroup::first();

        if (!$session && $this->selectedSessionId !== null) {
            // Selected a specific session ID that doesn't exist
            return [
                'session' => null,
                'categoryResults' => collect(),
                'progress' => null,
                'sessions' => $allSessions,
                'brandGroupedAnalysis' => [],
                'workgroup' => $workgroup,
                'comprehensiveResults' => [],
                'competitorGroupRankings' => [],
                'isolatedProducts' => [],
                'nonRankableFeedback' => collect(),
            ];
        }

        // session === null means "Overall Results" — pass null to get all data combined
        $sessionId = $session?->id; // null = all sessions

        $results = $evalService->getSessionResults($sessionId);
        $progress = $evalService->getSessionProgress($sessionId);

        // Get comprehensive results for the overall view
        $comprehensiveResults = [];
        $competitorGroupRankings = [];
        $isolatedProducts = [];
        $nonRankableFeedback = collect();

        if ($workgroup) {
            $comprehensiveResults = $evalService->getComprehensiveResults($workgroup, $session);
            $competitorGroupRankings = $comprehensiveResults['competitor_group_rankings'] ?? [];
            $isolatedProducts = $comprehensiveResults['isolated_products'] ?? [];
            $nonRankableFeedback = $comprehensiveResults['non_rankable_feedback'] ?? collect();
        }

        // Enhance category results with SAVER score breakdown
        $categoryResults = collect($results['rankable_categories'])->map(function ($cat) use ($sessionId) {
            $rankings = collect($cat['rankings'])->map(function ($item) {
                $product = $item['product'];
                $submissions = \App\Models\EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')
                    ->whereHas('member', fn($q) => $q->where('count_evaluations', true))
                    ->get();

                return array_merge($item, [
                    'capability_avg'     => $submissions->avg('capability_score'),
                    'usability_avg'      => $submissions->avg('usability_score'),
                    'affordability_avg'  => $submissions->avg('affordability_score'),
                    'maintainability_avg'=> $submissions->avg('maintainability_score'),
                    'deployability_avg'  => $submissions->avg('deployability_score'),
                    'advance_yes'        => $submissions->where('advance_recommendation', 'yes')->count(),
                    'advance_no'         => $submissions->where('advance_recommendation', 'no')->count(),
                    'deal_breakers'      => $submissions->where('has_deal_breaker', true)->count(),
                ]);
            });

            return array_merge($cat, ['rankings' => $rankings]);
        });

        return [
            'session'               => $session,
            'categoryResults'       => $categoryResults,
            'progress'              => $progress,
            'sessions'              => $allSessions,
            'brandGroupedAnalysis'  => $evalService->getBrandGroupedAnalysis($sessionId),
            'workgroup'             => $workgroup,
            'comprehensiveResults'  => $comprehensiveResults,
            'competitorGroupRankings' => $competitorGroupRankings,
            'isolatedProducts'      => $isolatedProducts,
            'nonRankableFeedback'   => $nonRankableFeedback instanceof \Illuminate\Support\Collection ? $nonRankableFeedback : collect($nonRankableFeedback),
            'granularToolGroupings' => $evalService->getGranularToolGroupings($sessionId),
        ];
    }

    // ─── Access Control ─────────────────────────────────────────────
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        if ($user->hasRole(['super_admin', 'admin', 'logistics_admin'])) {
            return true;
        }

        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
