<?php

namespace App\Filament\Workgroup\Pages;

use App\Filament\Workgroup\Pages\EvaluationFormPage;
use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
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

    public ?string $activeTab = 'pending';
    
    public ?string $selectedSession = null;

    public function mount(): void
    {
        // Set default session to active session
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

                BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'draft',
                        'success' => 'submitted',
                        'gray' => 'pending',
                    ]),

                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('-'),
            ])
            ->actions([
                Action::make('evaluate')
                    ->label('Evaluate')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->url(fn ($record) => EvaluationFormPage::getUrl(['productId' => $record instanceof CandidateProduct ? $record->id : $record->candidateProduct->id]))
                    ->visible(fn ($record) => $this->activeTab === 'pending'),
                Action::make('edit_draft')
                    ->label('Continue')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->url(fn ($record) => EvaluationFormPage::getUrl(['productId' => $record instanceof CandidateProduct ? $record->id : $record->candidateProduct->id]))
                    ->visible(fn ($record) => $this->activeTab === 'drafts'),
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn ($record) => EvaluationFormPage::getUrl(['productId' => $record instanceof CandidateProduct ? $record->id : $record->candidateProduct->id]))
                    ->visible(fn ($record) => $this->activeTab === 'completed'),
            ])
            ->emptyStateHeading('No evaluations found')
            ->emptyStateDescription('No evaluations match the current filter.');
    }

    protected function getEvaluationsQuery(): Builder
    {
        $member = $this->getCurrentMember();
        
        if (!$member) {
            return CandidateProduct::whereNull('id');
        }

        $sessionId = $this->selectedSession ? (int) $this->selectedSession : null;

        if ($this->activeTab === 'pending') {
            // Get products without submissions
            $evaluatedProductIds = EvaluationSubmission::where('workgroup_member_id', $member->id)
                ->pluck('candidate_product_id')
                ->toArray();
                
            return CandidateProduct::where('workgroup_session_id', $sessionId)
                ->whereNotIn('id', $evaluatedProductIds)
                ->with('category');
        } elseif ($this->activeTab === 'drafts') {
            return EvaluationSubmission::where('workgroup_member_id', $member->id)
                ->where('status', 'draft')
                ->with(['candidateProduct.category']);
        } elseif ($this->activeTab === 'completed') {
            return EvaluationSubmission::where('workgroup_member_id', $member->id)
                ->where('status', 'submitted')
                ->with(['candidateProduct.category']);
        }

        return CandidateProduct::whereNull('id');
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        $user = Auth::user();
        
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }

    public function updatedActiveTab(): void
    {
        $this->dispatch('$refresh');
    }

    public function updatedSelectedSession(): void
    {
        $this->dispatch('$refresh');
    }
}
