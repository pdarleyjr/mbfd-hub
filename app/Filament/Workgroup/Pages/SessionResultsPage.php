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
        return $session ? "Results: {$session->name}" : 'Session Results';
    }

    public function getSubheading(): ?string
    {
        $session = $this->getSelectedSession();
        if (!$session) {
            return 'No active evaluation session found.';
        }

        $progress = app(EvaluationService::class)->getSessionProgress($session->id);
        return "Completion: {$progress['completion_percentage']}% — {$progress['submitted_submissions']} of {$progress['max_possible_submissions']} evaluations submitted";
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $activeSession = WorkgroupSession::active()->first();
        $this->selectedSessionId = $activeSession?->id;
    }

    public function getSelectedSession(): ?WorkgroupSession
    {
        if ($this->selectedSessionId) {
            return WorkgroupSession::find($this->selectedSessionId);
        }
        return WorkgroupSession::active()->first();
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
                'session' => $session,
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
        return [
            Action::make('selectSession')
                ->label('Switch Session')
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->form([
                    Select::make('session_id')
                        ->label('Evaluation Session')
                        ->options(fn () => WorkgroupSession::orderByDesc('created_at')->pluck('name', 'id')->toArray())
                        ->default(fn () => $this->selectedSessionId)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->selectedSessionId = $data['session_id'] ?? null;
                }),
        ];
    }

    // ─── View Data (passed to blade) ────────────────────────────────
    protected function getViewData(): array
    {
        $session = $this->getSelectedSession();
        if (!$session) {
            return [
                'session' => null,
                'categoryResults' => collect(),
                'progress' => null,
                'sessions' => WorkgroupSession::orderByDesc('created_at')->get(),
            ];
        }

        $evalService = app(EvaluationService::class);
        $results = $evalService->getSessionResults($session->id);
        $progress = $evalService->getSessionProgress($session->id);

        // Enhance category results with SAVER score breakdown
        $categoryResults = collect($results['rankable_categories'])->map(function ($cat) {
            $rankings = collect($cat['rankings'])->map(function ($item) {
                $product = $item['product'];
                $submissions = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')
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
            'sessions' => WorkgroupSession::orderByDesc('created_at')->get(),
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
