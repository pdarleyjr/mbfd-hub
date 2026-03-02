<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Exports\WorkgroupCompletionStatusExporter;
use App\Filament\Workgroup\Exports\WorkgroupFeedbackExporter;
use App\Filament\Workgroup\Exports\WorkgroupFinalistsExporter;
use App\Filament\Workgroup\Exports\WorkgroupScoresExporter;
use App\Filament\Workgroup\Widgets\CategoryRankingsWidget;
use App\Filament\Workgroup\Widgets\FinalistsWidget;
use App\Filament\Workgroup\Widgets\NonRankableFeedbackWidget;
use App\Models\EvaluationCategory;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Actions\ExportAction;
use Filament\Actions\SelectAction;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Session Results page showing detailed rankings by category
 * with export functionality.
 */
class SessionResultsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $title = 'Session Results';

    protected static string $view = 'filament.workgroup.pages.session-results';

    protected static ?string $navigationLabel = 'Results';

    protected static ?string $slug = 'session-results';

    public ?WorkgroupSession $selectedSession = null;
    public ?EvaluationCategory $selectedCategory = null;

    public function getHeading(): string
    {
        $session = $this->selectedSession ?? WorkgroupSession::active()->first();
        return $session ? "Results: {$session->name}" : 'Session Results';
    }

    public function getSubheading(): ?string
    {
        return 'View rankings, finalists, and export evaluation results.';
    }

    public function mount(): void
    {
        parent::mount();

        abort_unless(static::canAccess(), 403);
        
        $this->selectedSession = WorkgroupSession::active()->first();
    }

    public function getWidgets(): array
    {
        return [
            FinalistsWidget::class,
            CategoryRankingsWidget::class,
            NonRankableFeedbackWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return [
            'sm' => 1,
            'md' => 2,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            SelectAction::make('selectSession')
                ->label('Select Session')
                ->options(fn () => $this->getSessionOptions())
                ->default(fn () => $this->selectedSession?->id)
                ->after(fn (SelectAction $action) => $action->successNotificationTitle('Session selected'))
                ->action(function (SelectAction $action, ?int $sessionId) {
                    $this->selectedSession = $sessionId 
                        ? WorkgroupSession::find($sessionId) 
                        : WorkgroupSession::active()->first();
                }),

            SelectAction::make('selectCategory')
                ->label('Filter by Category')
                ->options(fn () => $this->getCategoryOptions())
                ->nullable()
                ->after(fn (SelectAction $action) => $action->successNotificationTitle('Category selected'))
                ->action(function (SelectAction $action, ?int $categoryId) {
                    $this->selectedCategory = $categoryId 
                        ? EvaluationCategory::find($categoryId) 
                        : null;
                }),

            ExportAction::make('exportFinalists')
                ->label('Export Finalists')
                ->exporter(WorkgroupFinalistsExporter::class),

            ExportAction::make('exportScores')
                ->label('Export All Scores')
                ->exporter(WorkgroupScoresExporter::class),

            ExportAction::make('exportCompletion')
                ->label('Export Completion Status')
                ->exporter(WorkgroupCompletionStatusExporter::class),

            ExportAction::make('exportFeedback')
                ->label('Export Feedback')
                ->exporter(WorkgroupFeedbackExporter::class),
        ];
    }

    protected function getSessionOptions(): array
    {
        return WorkgroupSession::all()
            ->pluck('name', 'id')
            ->toArray();
    }

    protected function getCategoryOptions(): array
    {
        if (!$this->selectedSession) {
            return [];
        }

        return EvaluationCategory::active()
            ->ordered()
            ->pluck('name', 'id')
            ->toArray();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        $member = WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return $member && in_array($member->role, ['admin', 'facilitator']);
    }

    /**
     * Get rankable categories for display.
     */
    public function getRankableCategories(): array
    {
        if (!$this->selectedSession) {
            return [];
        }

        return EvaluationCategory::rankable()
            ->active()
            ->ordered()
            ->get()
            ->toArray();
    }

    /**
     * Get non-rankable categories for display.
     */
    public function getNonRankableCategories(): array
    {
        if (!$this->selectedSession) {
            return [];
        }

        return EvaluationCategory::where('is_rankable', false)
            ->active()
            ->ordered()
            ->get()
            ->toArray();
    }

    /**
     * Get finalists data for display.
     */
    public function getFinalistsData(): array
    {
        if (!$this->selectedSession) {
            return [];
        }

        return FinalistsWidget::getAllFinalists();
    }

    /**
     * Get feedback data for display.
     */
    public function getFeedbackData(): array
    {
        if (!$this->selectedSession) {
            return [];
        }

        return NonRankableFeedbackWidget::getAggregatedFeedback();
    }
}
