<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Exports\WorkgroupAIReportExporter;
use App\Filament\Workgroup\Exports\WorkgroupCompletionStatusExporter;
use App\Filament\Workgroup\Exports\WorkgroupFeedbackExporter;
use App\Filament\Workgroup\Exports\WorkgroupFinalistsExporter;
use App\Filament\Workgroup\Exports\WorkgroupScoresExporter;
use App\Models\EvaluationCategory;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Session Results page showing export functionality and AI report generation.
 * NOTE: Widget rendering disabled due to Filament v2/v3 compatibility issues in FinalistsWidget.
 * Use Export Actions to access the data.
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
        abort_unless(static::canAccess(), 403);
        
        $this->selectedSession = WorkgroupSession::active()->first();
    }

    public function getWidgets(): array
    {
        // Widgets disabled — FinalistsWidget uses Filament v2 BadgeColumn (not v3 compatible)
        // TODO: Fix FinalistsWidget to use TextColumn::badge() before re-enabling
        return [];
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
            Action::make('selectSession')
                ->label('Select Session')
                ->form([
                    Select::make('session_id')
                        ->label('Session')
                        ->options(fn () => $this->getSessionOptions())
                        ->default(fn () => $this->selectedSession?->id),
                ])
                ->action(function (array $data) {
                    $this->selectedSession = isset($data['session_id'])
                        ? WorkgroupSession::find($data['session_id'])
                        : WorkgroupSession::active()->first();
                }),

            Action::make('selectCategory')
                ->label('Filter by Category')
                ->form([
                    Select::make('category_id')
                        ->label('Category')
                        ->options(fn () => $this->getCategoryOptions())
                        ->nullable(),
                ])
                ->action(function (array $data) {
                    $this->selectedCategory = isset($data['category_id'])
                        ? EvaluationCategory::find($data['category_id'])
                        : null;
                }),

            ExportAction::make('exportAIReport')
                ->label('🤖 Export AI Report')
                ->color('violet')
                ->exporter(WorkgroupAIReportExporter::class)
                ->tooltip('Export all products with AI-generated analytical summaries — for Health & Safety Committee presentation'),

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

        // All active workgroup members can view session results (read-only access)
        // Admins and super_admins also have access
        if ($user->hasRole(['super_admin', 'admin', 'logistics_admin'])) {
            return true;
        }

        $member = WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return $member !== null;
    }
}
