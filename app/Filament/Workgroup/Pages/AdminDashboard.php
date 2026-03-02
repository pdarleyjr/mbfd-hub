<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Exports\WorkgroupCompletionStatusExporter;
use App\Filament\Workgroup\Exports\WorkgroupFeedbackExporter;
use App\Filament\Workgroup\Exports\WorkgroupFinalistsExporter;
use App\Filament\Workgroup\Exports\WorkgroupScoresExporter;
use App\Filament\Workgroup\Widgets\CategoryRankingsWidget;
use App\Filament\Workgroup\Widgets\FinalistsWidget;
use App\Filament\Workgroup\Widgets\NonRankableFeedbackWidget;
use App\Filament\Workgroup\Widgets\SessionProgressWidget;
use App\Filament\Workgroup\Widgets\WorkgroupAdminStatsWidget;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Admin Dashboard page showing system-wide analytics and rankings.
 * Accessible to facilitators and admins.
 */
class AdminDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Admin Dashboard';

    protected static string $view = 'filament.workgroup.pages.admin-dashboard';

    protected static ?string $navigationLabel = 'Admin Dashboard';

    protected static ?string $slug = 'admin-dashboard';

    public function getHeading(): string
    {
        return 'Workgroup Analytics Dashboard';
    }

    public function getSubheading(): ?string
    {
        return 'Overview of all workgroups, sessions, evaluations, and results.';
    }

    public function getWidgets(): array
    {
        return [
            WorkgroupAdminStatsWidget::class,
            SessionProgressWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'lg' => 3,
            'xl' => 4,
        ];
    }

    protected function getHeaderActions(): array
    {
        $session = WorkgroupSession::active()->first();

        return [
            ExportAction::make('exportFinalists')
                ->label('Export Finalists')
                ->exporter(WorkgroupFinalistsExporter::class)
                ->visible(fn () => $session !== null),

            ExportAction::make('exportScores')
                ->label('Export Scores')
                ->exporter(WorkgroupScoresExporter::class)
                ->visible(fn () => $session !== null),

            ExportAction::make('exportCompletion')
                ->label('Export Completion Status')
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
        
        if (!$user) {
            return false;
        }

        $member = WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return $member && in_array($member->role, ['admin', 'facilitator']);
    }

    public function mount(): void
    {
        parent::mount();

        abort_unless(static::canAccess(), 403);
    }
}
