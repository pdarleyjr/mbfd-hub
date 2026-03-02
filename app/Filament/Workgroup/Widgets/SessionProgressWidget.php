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
 */
class SessionProgressWidget extends BaseWidget
{
    public ?WorkgroupSession $session = null;

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
        $totalMembers = WorkgroupMember::where('is_active', true)->count();
        
        // Get completed submissions count
        $completedSubmissions = EvaluationSubmission::where('status', 'submitted')
            ->whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $session->id)
            )
            ->count();

        // Calculate completion percentage
        $totalPossible = $totalProducts * $totalMembers;
        $completionPercentage = $totalPossible > 0 
            ? round(($completedSubmissions / $totalPossible) * 100, 1) 
            : 0;

        // Get evaluator breakdown
        $evaluatorStats = $this->getEvaluatorStats($session);

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
                ->description('Active members')
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

            Stat::make('Pending', $totalPossible - $completedSubmissions)
                ->description('Awaiting submission')
                ->descriptionIcon('heroicon-o-clock')
                ->color('warning'),
        ];
    }

    protected function getEvaluatorStats(WorkgroupSession $session): array
    {
        $members = WorkgroupMember::where('is_active', true)
            ->with(['submissions' => fn($q) => 
                $q->whereHas('candidateProduct', fn($sq) => 
                    $sq->where('workgroup_session_id', $session->id)
                )
            ])
            ->get();

        $stats = [];
        foreach ($members as $member) {
            $completed = $member->submissions()->where('status', 'submitted')->count();
            $total = $session->candidateProducts()->count();
            
            $stats[] = [
                'name' => $member->user->name ?? 'Unknown',
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
