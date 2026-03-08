<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Widgets\CategoryRankingsWidget;
use App\Filament\Workgroup\Widgets\FinalistsWidget;
use App\Filament\Workgroup\Widgets\SessionProgressWidget;
use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Services\Workgroup\EvaluationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Session Results — comprehensive evaluation results dashboard.
 *
 * Shows: session progress stats, category rankings, finalists table,
 * AI executive report generator, and per-category drill-down data.
 * All widgets receive the selected session via Livewire properties.
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
        return WorkgroupSession::orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')->get();
    }

    /**
     * Livewire method to switch session from the Blade dropdown.
     */
    public function switchSession(?int $sessionId): void
    {
        $this->selectedSessionId = $sessionId;
    }

    // ─── Header Widgets ─────────────────────────────────────────────
    protected function getHeaderWidgets(): array
    {
        $session = $this->getSelectedSession();
        if (!$session) {
            // Overall Results: no session-specific progress widget
            return [];
        }

        return [
            SessionProgressWidget::make([
                'session' => $session,
            ]),
        ];
    }

    // ─── Footer Widgets (rankings table) ────────────────────────────
    protected function getFooterWidgets(): array
    {
        $session = $this->getSelectedSession();

        return [
            FinalistsWidget::make([
                'session' => $session, // null = overall
            ]),
        ];
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
        $allSessions = WorkgroupSession::orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')->get();

        if (!$session && $this->selectedSessionId !== null) {
            // Selected a specific session ID that doesn't exist
            return [
                'session' => null,
                'categoryResults' => collect(),
                'progress' => null,
                'sessions' => $allSessions,
                'brandGroupedAnalysis' => [],
            ];
        }

        // session === null means "Overall Results" — pass null to get all data combined
        $sessionId = $session?->id; // null = all sessions

        $results = $evalService->getSessionResults($sessionId);
        $progress = $evalService->getSessionProgress($sessionId);

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
