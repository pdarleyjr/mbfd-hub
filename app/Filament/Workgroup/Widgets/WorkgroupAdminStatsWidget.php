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
        $countableMembers = WorkgroupMember::where('is_active', true)->where('count_evaluations', true)->count();
        $totalMembers = WorkgroupMember::where('is_active', true)->count();
        $countableMemberIds = WorkgroupMember::where('is_active', true)->where('count_evaluations', true)->pluck('id');
        $pendingEvaluations = $this->getPendingEvaluationsCount();
        $completedEvaluations = EvaluationSubmission::where('status', 'submitted')
            ->whereIn('workgroup_member_id', $countableMemberIds)
            ->count();
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

            Stat::make('Evaluators', $countableMembers)
                ->description("{$totalMembers} total members ({$countableMembers} counting)")
                ->descriptionIcon('heroicon-o-users')
                ->color('info'),

            Stat::make('Pending Evaluations', $pendingEvaluations)
                ->description('Awaiting submission')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->color($pendingEvaluations > 0 ? 'warning' : 'success'),

            Stat::make('Completed', $completedEvaluations)
                ->description('Countable submissions')
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
        $countableMembers = WorkgroupMember::where('is_active', true)->where('count_evaluations', true)->count();
        $countableMemberIds = WorkgroupMember::where('is_active', true)->where('count_evaluations', true)->pluck('id');
        
        if ($totalProducts === 0 || $countableMembers === 0) {
            return 0;
        }

        $completedSubmissions = EvaluationSubmission::where('status', 'submitted')
            ->whereIn('workgroup_member_id', $countableMemberIds)
            ->whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $activeSession->id)
            )
            ->count();

        $totalPossible = $totalProducts * $countableMembers;
        
        return max(0, $totalPossible - $completedSubmissions);
    }
}
