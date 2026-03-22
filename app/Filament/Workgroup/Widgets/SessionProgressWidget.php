<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Widget showing per-session completion progress, evaluator status,
 * and products evaluated.
 *
 * IMPORTANT (2026-03-07 fix — ERROR-014): member counts now scoped to
 * session_workgroup_member_attendance pivot table, not all active members.
 */
class SessionProgressWidget extends BaseWidget
{
    public ?WorkgroupSession $session = null;

    protected static ?string $pollingInterval = '30s';
    
    protected function getStats(): array
    {
        $session = $this->session ?? WorkgroupSession::active()->first();
        
        if (!$session) {
            return [
                Stat::make('No Active Session', 'N/A')
                    ->description('No evaluation session in progress')
                    ->descriptionIcon('heroicon-o-calendar')
                    ->color('gray'),
            ];
        }

        $totalProducts = $session->candidateProducts()->count();

        // Count only members who: (a) attended this session AND (b) have count_evaluations=true.
        // Fallback to all active countable members if attendance table is empty for this session.
        $attendingMemberIds = $this->getAttendingMemberIds($session);

        if (empty($attendingMemberIds)) {
            // No attendance configured yet — fall back gracefully
            $attendingMemberIds = WorkgroupMember::where('is_active', true)
                ->where('count_evaluations', true)
                ->pluck('id')
                ->toArray();
        }

        $totalMembers = count($attendingMemberIds);

        // Get completed submissions — only from attending countable members
        $completedSubmissions = EvaluationSubmission::where('status', 'submitted')
            ->whereIn('workgroup_member_id', $attendingMemberIds)
            ->whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $session->id)
            )
            ->count();

        // Calculate completion percentage
        $totalPossible = $totalProducts * $totalMembers;
        $completionPercentage = $totalPossible > 0 
            ? round(($completedSubmissions / $totalPossible) * 100, 1) 
            : 0;

        return [
            Stat::make('Session', $session->name)
                ->description($session->status)
                ->descriptionIcon('heroicon-o-calendar')
                ->color($session->isActive() ? 'success' : 'gray'),

            Stat::make('Products', $totalProducts)
                ->description('Being evaluated')
                ->descriptionIcon('heroicon-o-cube')
                ->color('info'),

            Stat::make('Evaluators', $totalMembers)
                ->description('Attending members')
                ->descriptionIcon('heroicon-o-users')
                ->color('primary'),

            Stat::make('Progress', $completionPercentage . '%')
                ->description("{$completedSubmissions} of {$totalPossible} completed")
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color($this->getProgressColor($completionPercentage)),

            Stat::make('Completed', $completedSubmissions)
                ->description('Total submissions')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Pending', max(0, $totalPossible - $completedSubmissions))
                ->description('Awaiting submission')
                ->descriptionIcon('heroicon-o-clock'),
        ];
    }

    /**
     * Get IDs of members who attended the given session and have count_evaluations=true.
     */
    protected function getAttendingMemberIds(WorkgroupSession $session): array
    {
        return WorkgroupMember::where('is_active', true)
            ->where('count_evaluations', true)
            ->whereHas('sessionsAttended', fn($q) =>
                $q->where('workgroup_sessions.id', $session->id)
            )
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get per-evaluator breakdown, scoped to attending members only.
     *
     * @param int[] $attendingMemberIds
     */
    protected function getEvaluatorStats(WorkgroupSession $session, array $attendingMemberIds): array
    {
        if (empty($attendingMemberIds)) {
            return [];
        }

        $members = WorkgroupMember::where('is_active', true)
            ->where('count_evaluations', true)
            ->whereIn('id', $attendingMemberIds)
            ->with(['submissions' => fn($q) => 
                $q->whereHas('candidateProduct', fn($sq) => 
                    $sq->where('workgroup_session_id', $session->id)
                )
            ])
            ->get();

        $stats = [];
        foreach ($members as $member) {
            $completed = $member->submissions->where('status', 'submitted')->count();
            $total = $session->candidateProducts()->count();
            
            $stats[] = [
                'name' => $member->user?->name ?? 'Unknown',
                'completed' => $completed,
                'total' => $total,
                'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
            ];
        }

        return $stats;
    }

    protected function getProgressColor(float $percentage): string
    {
        if ($percentage >= 80) {
            return 'success';
        } elseif ($percentage >= 50) {
            return 'warning';
        } elseif ($percentage > 0) {
            return 'danger';
        }
        return 'gray';
    }
}
