<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\EvaluationSubmission;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Admin-level stats widget showing system-wide workgroup statistics.
 * Only visible to facilitators and admins.
 */
class WorkgroupAdminStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalWorkgroups = Workgroup::count();
        $activeWorkgroups = Workgroup::active()->count();
        $activeSessions = WorkgroupSession::active()->count();
        $totalMembers = WorkgroupMember::where('is_active', true)->count();
        $pendingEvaluations = $this->getPendingEvaluationsCount();
        $completedEvaluations = EvaluationSubmission::submitted()->count();
        $totalSessions = WorkgroupSession::count();

        return [
            Stat::make('Total Workgroups', $totalWorkgroups)
                ->description("{$activeWorkgroups} active")
                ->descriptionIcon('heroicon-o-user-group')
                ->color('primary'),

            Stat::make('Active Sessions', $activeSessions)
                ->description("{$totalSessions} total sessions")
                ->descriptionIcon('heroicon-o-calendar')
                ->color($activeSessions > 0 ? 'success' : 'gray'),

            Stat::make('Total Members', $totalMembers)
                ->description('Active members')
                ->descriptionIcon('heroicon-o-users')
                ->color('info'),

            Stat::make('Pending Evaluations', $pendingEvaluations)
                ->description('Awaiting submission')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->color($pendingEvaluations > 0 ? 'warning' : 'success'),

            Stat::make('Completed', $completedEvaluations)
                ->description('Total submissions')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }

    protected function getPendingEvaluationsCount(): int
    {
        $activeSession = WorkgroupSession::active()->first();
        
        if (!$activeSession) {
            return 0;
        }

        $totalProducts = $activeSession->candidateProducts()->count();
        $totalMembers = WorkgroupMember::where('is_active', true)->count();
        
        if ($totalProducts === 0 || $totalMembers === 0) {
            return 0;
        }

        $completedSubmissions = EvaluationSubmission::where('status', 'submitted')
            ->whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $activeSession->id)
            )
            ->count();

        // Total possible = products * members
        $totalPossible = $totalProducts * $totalMembers;
        
        return max(0, $totalPossible - $completedSubmissions);
    }
}
