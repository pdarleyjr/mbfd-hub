<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Pages\EvaluationFormPage;
use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Services\Workgroup\EvaluationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Evaluations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static string $view = 'filament-workgroup.pages.evaluations';
    protected static ?string $title = 'Evaluations';
    protected static ?string $navigationLabel = 'Evaluations';

    public ?string $selectedSession = null;

    public function mount(): void
    {
        $member = $this->getCurrentMember();
        if (!$member) {
            return;
        }

        // Get sessions the member has attended (or has existing submissions for)
        $attendedSessions = $this->getAttendedSessions($member);

        if ($attendedSessions->isNotEmpty()) {
            // Prefer the active session if attended, else pick the most recent attended session
            $activeAttended = $attendedSessions->firstWhere('status', 'active');
            $this->selectedSession = (string) ($activeAttended?->id ?? $attendedSessions->first()->id);
        } elseif ($member->workgroup) {
            // No attendance configured yet — fall back to active session
            $activeSession = $member->workgroup->sessions()->active()->first();
            if ($activeSession) {
                $this->selectedSession = (string) $activeSession->id;
            }
        }
    }

    /**
     * Return sessions the member is allowed to access:
     * - For admin/facilitator role: ALL sessions in the workgroup
     * - For base member role: sessions with attendance record OR existing submissions
     */
    public function getAttendedSessions(WorkgroupMember $member): \Illuminate\Support\Collection
    {
        if (!$member->workgroup) {
            return collect();
        }

        $workgroupId = $member->workgroup_id;

        // Admin and facilitator roles see ALL sessions in their workgroup
        if (in_array($member->role, ['admin', 'facilitator'])) {
            return \App\Models\WorkgroupSession::where('workgroup_id', $workgroupId)
                ->orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->get();
        }

        // Base members: sessions in attendance pivot + sessions with existing submissions
        $attendedIds = DB::table('session_workgroup_member_attendance')
            ->where('workgroup_member_id', $member->id)
            ->pluck('workgroup_session_id')
            ->toArray();

        $submittedSessionIds = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->join('candidate_products', 'candidate_products.id', '=', 'evaluation_submissions.candidate_product_id')
            ->whereNotNull('candidate_products.workgroup_session_id')
            ->pluck('candidate_products.workgroup_session_id')
            ->unique()
            ->toArray();

        $allSessionIds = array_unique(array_merge($attendedIds, $submittedSessionIds));

        if (empty($allSessionIds)) {
            return collect();
        }

        return \App\Models\WorkgroupSession::where('workgroup_id', $workgroupId)
            ->whereIn('id', $allSessionIds)
            ->orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        $member = $this->getCurrentMember();
        if (!$member) {
            return [];
        }

        $attendedSessions = $this->getAttendedSessions($member);

        // Only show session switcher if member attended more than 1 session
        if ($attendedSessions->count() <= 1) {
            return [];
        }

        $sessionOptions = $attendedSessions->pluck('name', 'id')->toArray();

        return [
            Action::make('switchSession')
                ->label(function () use ($attendedSessions): string {
                    if ($this->selectedSession) {
                        $current = $attendedSessions->firstWhere('id', (int) $this->selectedSession);
                        return 'Session: ' . ($current?->name ?? 'Select Session');
                    }
                    return 'Select Session';
                })
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->form([
                    Select::make('session_id')
                        ->label('Switch to Session')
                        ->options($sessionOptions)
                        ->default(fn () => $this->selectedSession ? (int) $this->selectedSession : null)
                        ->required()
                        ->helperText('Only sessions you attended are shown.'),
                ])
                ->action(function (array $data): void {
                    $this->selectedSession = (string) $data['session_id'];
                    // Reset table to page 1 after switching
                    $this->resetTable();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $member = $this->getCurrentMember();

        return $table
            ->query($this->getEvaluationsQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('manufacturer')
                    ->label('Manufacturer')
                    ->searchable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('eval_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function ($record) use ($member) {
                        if (!$member) return 'Not Started';
                        $sub = $record->submissions->firstWhere('workgroup_member_id', $member->id);
                        if (!$sub) return 'Not Started';
                        return $sub->status === 'submitted' ? 'Completed' : 'In Progress';
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Completed' => 'success',
                        'In Progress' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->actions([
                TableAction::make('evaluate')
                    ->label(function ($record) use ($member) {
                        if (!$member) return 'Evaluate';
                        $sub = $record->submissions->firstWhere('workgroup_member_id', $member->id);
                        if (!$sub) return 'Evaluate';
                        return $sub->status === 'submitted' ? 'View' : 'Continue';
                    })
                    ->icon(function ($record) use ($member) {
                        if (!$member) return 'heroicon-o-pencil-square';
                        $sub = $record->submissions->firstWhere('workgroup_member_id', $member->id);
                        if ($sub && $sub->status === 'submitted') return 'heroicon-o-eye';
                        return 'heroicon-o-pencil-square';
                    })
                    ->color(function ($record) use ($member) {
                        if (!$member) return 'primary';
                        $sub = $record->submissions->firstWhere('workgroup_member_id', $member->id);
                        if ($sub && $sub->status === 'submitted') return 'gray';
                        if ($sub) return 'warning';
                        return 'primary';
                    })
                    ->url(fn ($record) => EvaluationFormPage::getUrl(['productId' => $record->id])),
            ])
            ->emptyStateHeading('No products to evaluate')
            ->emptyStateDescription('No candidate products have been added to this session yet, or you have not been marked as attending this session.');
    }

    protected function getEvaluationsQuery(): Builder
    {
        $member = $this->getCurrentMember();
        if (!$member) {
            return CandidateProduct::whereNull('id');
        }

        $sessionId = $this->selectedSession ? (int) $this->selectedSession : null;

        if (!$sessionId) {
            return CandidateProduct::whereNull('id');
        }

        // Enforce attendance gate: member must be attending this session to see products
        // (or already have existing submissions — backfill safety)
        $evalService = app(EvaluationService::class);
        if ($member->role === 'member' && !$evalService->canMemberAccessSession($member, $sessionId)) {
            return CandidateProduct::whereNull('id');
        }

        return CandidateProduct::where('workgroup_session_id', $sessionId)
            ->with(['category', 'session'])
            ->with(['submissions' => function ($q) use ($member, $sessionId) {
                $q->where('workgroup_member_id', $member?->id);
                if ($sessionId) {
                    $q->whereHas('candidateProduct', fn($sq) => $sq->where('workgroup_session_id', $sessionId));
                }
            }])
            ->orderBy('category_id')
            ->orderBy('name');
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        return WorkgroupMember::where('user_id', Auth::id())
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }
}
