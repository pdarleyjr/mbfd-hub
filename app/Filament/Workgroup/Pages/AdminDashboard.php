<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Exports\WorkgroupCompletionStatusExporter;
use App\Filament\Workgroup\Exports\WorkgroupFeedbackExporter;
use App\Filament\Workgroup\Exports\WorkgroupFinalistsExporter;
use App\Filament\Workgroup\Exports\WorkgroupScoresExporter;
use App\Filament\Workgroup\Widgets\AiSummaryWidget;
use App\Filament\Workgroup\Widgets\CategoryRankingsWidget;
use App\Filament\Workgroup\Widgets\EvaluatorTrackingWidget;
use App\Filament\Workgroup\Widgets\FinalistsWidget;
use App\Filament\Workgroup\Widgets\NonRankableFeedbackWidget;
use App\Filament\Workgroup\Widgets\ProductScoreChartWidget;
use App\Filament\Workgroup\Widgets\SessionProgressWidget;
use App\Filament\Workgroup\Widgets\WorkgroupAdminStatsWidget;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The Admin "Workgroup Data Hub" - single consolidated admin page.
 */
class AdminDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $title = 'Workgroup Data Hub';
    protected static string $view = 'filament-workgroup.pages.admin-dashboard';
    protected static ?string $navigationLabel = 'Data Hub';
    protected static ?string $navigationGroup = 'Analytics';
    protected static ?string $slug = 'admin-dashboard';
    protected static ?int $navigationSort = 1;

    public function getHeading(): string
    {
        return 'Workgroup Data Hub';
    }

    public function getSubheading(): ?string
    {
        return 'Live analytics, evaluator tracking, AI intelligence, and exports.';
    }

    public function getWidgets(): array
    {
        return [
            AiSummaryWidget::class,
            WorkgroupAdminStatsWidget::class,
            ProductScoreChartWidget::class,
            EvaluatorTrackingWidget::class,
            SessionProgressWidget::class,
            CategoryRankingsWidget::class,
            FinalistsWidget::class,
            NonRankableFeedbackWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return ['sm' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];
    }

    protected function getHeaderActions(): array
    {
        $session = WorkgroupSession::active()->first();

        return [
            Action::make('generateExecutiveReport')
                ->label('Generate Executive Report')
                ->icon('heroicon-o-document-arrow-down')
                ->color('primary')
                ->url(fn () => route('workgroup.executive-report'), shouldOpenInNewTab: true)
                ->visible(fn () => $session !== null),

            ExportAction::make('exportFinalists')
                ->label('Export Finalists')
                ->exporter(WorkgroupFinalistsExporter::class)
                ->visible(fn () => $session !== null),

            ExportAction::make('exportScores')
                ->label('Export Scores')
                ->exporter(WorkgroupScoresExporter::class)
                ->visible(fn () => $session !== null),

            ExportAction::make('exportCompletion')
                ->label('Export Completion')
                ->exporter(WorkgroupCompletionStatusExporter::class),

            ExportAction::make('exportFeedback')
                ->label('Export Feedback')
                ->exporter(WorkgroupFeedbackExporter::class)
                ->visible(fn () => $session !== null),
        ];
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        $member = WorkgroupMember::where('user_id', $user->id)->where('is_active', true)->first();
        return $member && in_array($member->role, ['admin', 'facilitator']);
    }

    public function mount(): void
    {
        parent::mount();
        abort_unless(static::canAccess(), 403);
    }
}
