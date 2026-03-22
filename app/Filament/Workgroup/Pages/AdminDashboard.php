<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Exports\WorkgroupAIReportExporter;
use App\Filament\Workgroup\Exports\WorkgroupCompletionStatusExporter;
use App\Filament\Workgroup\Exports\WorkgroupFeedbackExporter;
use App\Filament\Workgroup\Exports\WorkgroupFinalistsExporter;
use App\Filament\Workgroup\Exports\WorkgroupScoresExporter;
use App\Models\EvaluationSubmission;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Services\Workgroup\EvaluationService;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Admin Dashboard — system-wide analytics rendered inline (no child widgets).
 * Prevents ERROR-018 stale-state by passing all data via getViewData().
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

    protected function getHeaderActions(): array
    {
        $session = WorkgroupSession::active()->first();

        return [
            ExportAction::make('exportAIReport')
                ->label('🤖 Export AI Report')
                ->color('violet')
                ->exporter(WorkgroupAIReportExporter::class)
                ->tooltip('Export all products with AI-generated analytical summaries')
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
                ->label('Export Completion Status')
                ->exporter(WorkgroupCompletionStatusExporter::class),

            ExportAction::make('exportFeedback')
                ->label('Export Feedback')
                ->exporter(WorkgroupFeedbackExporter::class)
                ->visible(fn () => $session !== null),
        ];
    }

    // ─── View Data (inline stats — no child widgets) ─────────────────
    protected function getViewData(): array
    {
        $totalWorkgroups = Workgroup::count();
        $activeWorkgroups = Workgroup::active()->count();
        $activeSessions = WorkgroupSession::active()->count();
        $totalSessions = WorkgroupSession::count();
        $countableMembers = WorkgroupMember::where('is_active', true)->where('count_evaluations', true)->count();
        $totalMembers = WorkgroupMember::where('is_active', true)->count();
        $countableMemberIds = WorkgroupMember::where('is_active', true)->where('count_evaluations', true)->pluck('id');

        $completedEvaluations = EvaluationSubmission::where('status', 'submitted')
            ->whereIn('workgroup_member_id', $countableMemberIds)
            ->count();

        // Pending evaluations for active session
        $activeSession = WorkgroupSession::active()->first();
        $pendingEvaluations = 0;
        if ($activeSession) {
            $totalProducts = $activeSession->candidateProducts()->count();
            if ($totalProducts > 0 && $countableMembers > 0) {
                $completedForSession = EvaluationSubmission::where('status', 'submitted')
                    ->whereIn('workgroup_member_id', $countableMemberIds)
                    ->whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $activeSession->id))
                    ->count();
                $pendingEvaluations = max(0, ($totalProducts * $countableMembers) - $completedForSession);
            }
        }

        // Session progress for active session
        $progress = null;
        if ($activeSession) {
            $progress = app(EvaluationService::class)->getSessionProgress($activeSession->id);
        }

        return [
            'stats' => [
                ['label' => 'Total Workgroups', 'value' => $totalWorkgroups, 'desc' => "{$activeWorkgroups} active", 'icon' => 'heroicon-o-user-group', 'color' => 'primary'],
                ['label' => 'Active Sessions', 'value' => $activeSessions, 'desc' => "{$totalSessions} total sessions", 'icon' => 'heroicon-o-calendar', 'color' => $activeSessions > 0 ? 'success' : 'gray'],
                ['label' => 'Evaluators', 'value' => $countableMembers, 'desc' => "{$totalMembers} total members ({$countableMembers} counting)", 'icon' => 'heroicon-o-users', 'color' => 'info'],
                ['label' => 'Pending Evaluations', 'value' => $pendingEvaluations, 'desc' => 'Awaiting submission', 'icon' => 'heroicon-o-clipboard-document-check', 'color' => $pendingEvaluations > 0 ? 'warning' : 'success'],
                ['label' => 'Completed', 'value' => $completedEvaluations, 'desc' => 'Countable submissions', 'icon' => 'heroicon-o-check-circle', 'color' => 'success'],
            ],
            'progress' => $progress,
            'activeSession' => $activeSession,
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
