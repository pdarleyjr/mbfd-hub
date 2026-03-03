<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Pages\EvaluationFormPage;
use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class Evaluations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static string $view = 'filament-workgroup.pages.simple-page';
    protected static ?string $title = 'Evaluations';
    protected static ?string $navigationLabel = 'Evaluations';

    public ?string $selectedSession = null;

    public function mount(): void
    {
        $member = $this->getCurrentMember();
        if ($member && $member->workgroup) {
            $activeSession = $member->workgroup->sessions()->active()->first();
            if ($activeSession) {
                $this->selectedSession = (string) $activeSession->id;
            }
        }
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
                        // Get the submission from the eager loaded collection
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
                Action::make('evaluate')
                    ->label(function ($record) use ($member) {
                        if (!$member) return 'Evaluate';
                        // Get the submission from the eager loaded collection
                        $sub = $record->submissions->firstWhere('workgroup_member_id', $member->id);
                        if (!$sub) return 'Evaluate';
                        return $sub->status === 'submitted' ? 'View' : 'Continue';
                    })
                    ->icon(function ($record) use ($member) {
                        if (!$member) return 'heroicon-o-pencil-square';
                        // Get the submission from the eager loaded collection
                        $sub = $record->submissions->firstWhere('workgroup_member_id', $member->id);
                        if ($sub && $sub->status === 'submitted') return 'heroicon-o-eye';
                        return 'heroicon-o-pencil-square';
                    })
                    ->color(function ($record) use ($member) {
                        if (!$member) return 'primary';
                        // Get the submission from the eager loaded collection
                        $sub = $record->submissions->firstWhere('workgroup_member_id', $member->id);
                        if ($sub && $sub->status === 'submitted') return 'gray';
                        if ($sub) return 'warning';
                        return 'primary';
                    })
                    ->url(fn ($record) => EvaluationFormPage::getUrl(['productId' => $record->id])),
            ])
            ->emptyStateHeading('No products to evaluate')
            ->emptyStateDescription('No candidate products have been added to this session yet.');
    }

    protected function getEvaluationsQuery(): Builder
    {
        $member = $this->getCurrentMember();
        if (!$member) {
            return CandidateProduct::whereNull('id');
        }

        $sessionId = $this->selectedSession ? (int) $this->selectedSession : null;

        return CandidateProduct::where('workgroup_session_id', $sessionId)
            ->with(['category', 'session'])
            // Eager load submissions for the current member to avoid N+1
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
