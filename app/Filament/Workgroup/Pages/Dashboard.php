<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupNote;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $title = 'Workgroup Dashboard';

    protected static string $routePath = '/';

    protected static string $view = 'filament-workgroup.pages.dashboard';

    public ?int $selectedSessionId = null;

    public function mount(): void
    {
        $member = $this->getCurrentMember();
        if (!$member) {
            return;
        }

        // Default to the active session the member attended (or any active session)
        $attended = $this->getAccessibleSessions($member);
        $activeAttended = $attended->firstWhere('status', 'active');
        $this->selectedSessionId = $activeAttended?->id ?? $attended->first()?->id;
    }

    /**
     * Get sessions accessible to this member
     * (admin/facilitator: all sessions; member: attended or submitted)
     */
    public function getAccessibleSessions(WorkgroupMember $member): \Illuminate\Support\Collection
    {
        if (!$member->workgroup) {
            return collect();
        }

        $workgroupId = $member->workgroup_id;

        if (in_array($member->role, ['admin', 'facilitator'])) {
            return WorkgroupSession::where('workgroup_id', $workgroupId)
                ->orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')->get();
        }

        $attendedIds = DB::table('session_workgroup_member_attendance')
            ->where('workgroup_member_id', $member->id)
            ->pluck('workgroup_session_id')->toArray();

        $submittedIds = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->join('candidate_products', 'candidate_products.id', '=', 'evaluation_submissions.candidate_product_id')
            ->whereNotNull('candidate_products.workgroup_session_id')
            ->pluck('candidate_products.workgroup_session_id')->unique()->toArray();

        $allIds = array_unique(array_merge($attendedIds, $submittedIds));
        if (empty($allIds)) {
            return WorkgroupSession::where('workgroup_id', $workgroupId)->active()->get();
        }

        return WorkgroupSession::where('workgroup_id', $workgroupId)
            ->whereIn('id', $allIds)
            ->orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')->get();
    }

    public function getSubheading(): ?string
    {
        $session = $this->selectedSessionId ? WorkgroupSession::find($this->selectedSessionId) : null;
        if ($session) {
            return "Viewing: {$session->name} — Switch sessions using the buttons below.";
        }
        return 'Overview of your current workgroup, evaluations, and shared resources.';
    }

    public function getColumns(): int|string|array
    {
        return ['sm' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openNotes')
                ->label('My Notes')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(fn () => Notes::getUrl()),
            Action::make('openEvaluations')
                ->label('Evaluations')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->url(fn () => Evaluations::getUrl()),
            Action::make('openFiles')
                ->label('Files')
                ->icon('heroicon-o-document')
                ->color('gray')
                ->url(fn () => Files::getUrl()),
        ];
    }

    public function getWorkgroupStats(): array
    {
        $user = auth()->user();
        $workgroupMember = $this->getCurrentMember($user);

        if (!$workgroupMember) {
            return [
                Stat::make('No Workgroup', 'Not assigned')
                    ->description('Contact administrator')
                    ->descriptionIcon('heroicon-o-exclamation-triangle')
                    ->color('warning'),
            ];
        }

        $workgroup = $workgroupMember->workgroup;
        $session = $this->selectedSessionId ? WorkgroupSession::find($this->selectedSessionId) : null;
        if (!$session) {
            $session = $workgroup?->sessions()->active()->first();
        }

        $assignedFilesCount = $workgroup?->files()->count() ?? 0;
        $pendingEvaluationsCount = $this->getPendingEvaluationsCount($workgroupMember, $session);
        $myNotesCount = WorkgroupNote::where('workgroup_member_id', $workgroupMember->id)->count();
        $sharedUploadsCount = WorkgroupSharedUpload::where('workgroup_id', $workgroup?->id)->count();
        $completedEvaluationsCount = EvaluationSubmission::where('workgroup_member_id', $workgroupMember->id)
            ->where('status', 'submitted')->count();

        return [
            Stat::make('Workgroup', $workgroup?->name ?? 'None')
                ->description($workgroupMember->role)
                ->descriptionIcon('heroicon-o-user-group')
                ->color('primary'),

            Stat::make('Session', $session?->name ?? 'None')
                ->description($session ? $session->status : 'No active session')
                ->descriptionIcon('heroicon-o-calendar')
                ->color($session?->isActive() ? 'success' : 'gray'),

            Stat::make('Pending Evals', $pendingEvaluationsCount)
                ->description($session ? "For {$session->name}" : 'No session')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->color($pendingEvaluationsCount > 0 ? 'warning' : 'success'),

            Stat::make('Assigned Files', $assignedFilesCount)
                ->description('All sessions')
                ->descriptionIcon('heroicon-o-document')
                ->color('info'),

            Stat::make('My Notes', $myNotesCount)
                ->description('All sessions')
                ->descriptionIcon('heroicon-o-pencil-square')
                ->color('gray'),

            Stat::make('Shared Uploads', $sharedUploadsCount)
                ->description('All sessions')
                ->descriptionIcon('heroicon-o-cloud-arrow-up')
                ->color('indigo'),

            Stat::make('Completed', $completedEvaluationsCount)
                ->description('Total submitted')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }

    protected function getCurrentMember($user = null): ?WorkgroupMember
    {
        $user = $user ?? auth()->user();
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with('workgroup.sessions')
            ->first();
    }

    protected function getPendingEvaluationsCount(WorkgroupMember $member, ?WorkgroupSession $session): int
    {
        if (!$session) return 0;
        $totalProducts = $session->candidateProducts()->count();
        $evaluatedProducts = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $session->id))
            ->count();
        return max(0, $totalProducts - $evaluatedProducts);
    }
}
