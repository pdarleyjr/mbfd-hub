<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupNote;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class WorkgroupStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();
        $workgroupMember = $this->getWorkgroupMember($user);
        
        if (!$workgroupMember) {
            return [
                Stat::make('No Workgroup', 'Not assigned')
                    ->description('Contact administrator')
                    ->descriptionIcon('heroicon-o-exclamation-triangle')
                    ->color('warning'),
            ];
        }

        $workgroup = $workgroupMember->workgroup;
        $activeSession = $workgroup?->sessions()->active()->first();
        
        // Count assigned files
        $assignedFilesCount = $workgroup?->files()->when($activeSession, fn($q) => 
            $q->where('workgroup_session_id', $activeSession->id)
        )->count() ?? 0;

        // Count pending evaluations
        $pendingEvaluationsCount = $this->getPendingEvaluationsCount($workgroupMember, $activeSession);

        // Count my notes
        $myNotesCount = WorkgroupNote::where('workgroup_member_id', $workgroupMember->id)->count();

        // Count shared uploads
        $sharedUploadsCount = WorkgroupSharedUpload::where('workgroup_id', $workgroup?->id)
            ->when($activeSession, fn($q) => 
                $q->where('workgroup_session_id', $activeSession->id)
            )
            ->count();

        // Count completed evaluations
        $completedEvaluationsCount = EvaluationSubmission::where('workgroup_member_id', $workgroupMember->id)
            ->where('status', 'submitted')
            ->count();

        return [
            Stat::make('Workgroup', $workgroup?->name ?? 'None')
                ->description($workgroupMember->role)
                ->descriptionIcon('heroicon-o-user-group')
                ->color('primary'),

            Stat::make('Current Session', $activeSession?->name ?? 'None')
                ->description($activeSession ? $activeSession->status : 'No active session')
                ->descriptionIcon('heroicon-o-calendar')
                ->color($activeSession?->isActive() ? 'success' : 'gray'),

            Stat::make('Pending', $pendingEvaluationsCount)
                ->description('To be completed')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->color($pendingEvaluationsCount > 0 ? 'warning' : 'success'),

            Stat::make('Assigned Files', $assignedFilesCount)
                ->description('Available for review')
                ->descriptionIcon('heroicon-o-document')
                ->color('info'),

            Stat::make('My Notes', $myNotesCount)
                ->description('Private notes')
                ->descriptionIcon('heroicon-o-note')
                ->color('gray'),

            Stat::make('Shared', $sharedUploadsCount)
                ->description('Team shared files')
                ->descriptionIcon('heroicon-o-cloud-arrow-up')
                ->color('indigo'),

            Stat::make('Completed', $completedEvaluationsCount)
                ->description('Submitted')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }

    protected function getWorkgroupMember($user): ?WorkgroupMember
    {
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with('workgroup.sessions')
            ->first();
    }

    protected function getPendingEvaluationsCount(WorkgroupMember $member, ?WorkgroupSession $session): int
    {
        if (!$session) {
            return 0;
        }

        // Get candidate products for this session that haven't been evaluated
        $totalProducts = $session->candidateProducts()->count();
        $evaluatedProducts = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $session->id))
            ->count();

        return max(0, $totalProducts - $evaluatedProducts);
    }
}
