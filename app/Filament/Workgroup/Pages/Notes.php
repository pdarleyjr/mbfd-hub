<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\WorkgroupMember;
use App\Models\WorkgroupNote;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class Notes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static string $view = 'filament::pages.simple-page';

    protected static ?string $title = 'My Notes';
    
    protected static ?string $navigationLabel = 'Notes';

    public ?string $activeTab = 'all';
    
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createNote')
                ->label('New Note')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->form([
                    \Filament\Forms\Components\TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255),
                    \Filament\Forms\Components\RichEditor::make('content')
                        ->label('Content')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->createNote($data);
                })
                ->modalSubmitActionLabel('Save Note'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getNotesQuery())
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('preview')
                    ->label('Preview')
                    ->limit(50),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make()
                    ->label('Edit')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\RichEditor::make('content')
                            ->label('Content')
                            ->required(),
                    ])
                    ->action(function (WorkgroupNote $record, array $data): void {
                        $record->update($data);
                    }),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No notes yet')
            ->emptyStateDescription('Create your first note to get started.');
    }

    protected function getNotesQuery(): Builder
    {
        $member = $this->getCurrentMember();
        
        if (!$member) {
            return WorkgroupNote::whereNull('id');
        }

        $query = WorkgroupNote::where('workgroup_member_id', $member->id);

        if ($this->selectedSession) {
            $query->where('workgroup_session_id', (int) $this->selectedSession);
        }

        return $query->orderBy('created_at', 'desc');
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        $user = Auth::user();
        
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }

    protected function createNote(array $data): void
    {
        $member = $this->getCurrentMember();
        
        if (!$member) {
            return;
        }

        WorkgroupNote::create([
            'workgroup_member_id' => $member->id,
            'workgroup_session_id' => $this->selectedSession ? (int) $this->selectedSession : null,
            'title' => $data['title'],
            'content' => $data['content'],
        ]);
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
