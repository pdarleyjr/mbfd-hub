<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupNote;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
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

    protected static string $view = 'filament-workgroup.pages.simple-page';

    protected static ?string $title = 'My Notes';

    protected static ?string $navigationLabel = 'Notes';

    public ?string $activeTab = 'all';

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createNote')
                ->label('New Note')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->form($this->getNoteFormSchema())
                ->action(function (array $data): void {
                    $this->createNote($data);
                })
                ->modalSubmitActionLabel('Save Note'),
        ];
    }

    /**
     * Shared form schema for creating and editing notes.
     */
    protected function getNoteFormSchema(): array
    {
        $member = $this->getCurrentMember();
        $workgroupId = $member?->workgroup_id;

        // Get other members in same workgroup for share-with-specific-user dropdown
        $memberOptions = [];
        if ($workgroupId) {
            $memberOptions = WorkgroupMember::where('workgroup_id', $workgroupId)
                ->where('is_active', true)
                ->where('id', '!=', $member?->id)
                ->with('user')
                ->get()
                ->mapWithKeys(fn ($m) => [$m->user_id => $m->user?->name ?? "Member #{$m->id}"])
                ->toArray();
        }

        return [
            TextInput::make('title')
                ->label('Title')
                ->required()
                ->maxLength(255),
            RichEditor::make('content')
                ->label('Content')
                ->required(),
            Toggle::make('is_shared')
                ->label('Share this note')
                ->helperText('When enabled, this note will be visible to other workgroup members.')
                ->default(false)
                ->live(),
            Select::make('shared_with_user_id')
                ->label('Share with')
                ->helperText('Leave empty to share with ALL workgroup members, or select a specific person.')
                ->options($memberOptions)
                ->placeholder('Everyone in workgroup')
                ->nullable()
                ->visible(fn ($get) => $get('is_shared')),
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

                IconColumn::make('is_shared')
                    ->label('Shared')
                    ->boolean()
                    ->trueIcon('heroicon-o-share')
                    ->falseIcon('heroicon-o-lock-closed')
                    ->trueColor('success')
                    ->falseColor('gray'),

                TextColumn::make('sharedWith.name')
                    ->label('Shared With')
                    ->default('Everyone')
                    ->placeholder('—')
                    ->visible(fn () => true)
                    ->formatStateUsing(function ($state, WorkgroupNote $record) {
                        if (!$record->is_shared) return '—';
                        return $record->shared_with_user_id ? $state : 'Everyone';
                    }),

                TextColumn::make('member.user.name')
                    ->label('Author')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->actions([
                TableAction::make('share')
                    ->label('Share')
                    ->icon('heroicon-o-share')
                    ->color('success')
                    ->form(function (WorkgroupNote $record) {
                        $member = $this->getCurrentMember();
                        $memberOptions = [];
                        if ($member?->workgroup_id) {
                            $memberOptions = WorkgroupMember::where('workgroup_id', $member->workgroup_id)
                                ->where('is_active', true)
                                ->where('id', '!=', $member->id)
                                ->with('user')
                                ->get()
                                ->mapWithKeys(fn ($m) => [$m->user_id => $m->user?->name ?? "Member #{$m->id}"])
                                ->toArray();
                        }

                        return [
                            Toggle::make('is_shared')
                                ->label('Share this note')
                                ->default($record->is_shared),
                            Select::make('shared_with_user_id')
                                ->label('Share with')
                                ->options($memberOptions)
                                ->placeholder('Everyone in workgroup')
                                ->nullable()
                                ->default($record->shared_with_user_id),
                        ];
                    })
                    ->action(function (WorkgroupNote $record, array $data): void {
                        $record->update([
                            'is_shared' => $data['is_shared'] ?? false,
                            'shared_with_user_id' => ($data['is_shared'] ?? false) ? ($data['shared_with_user_id'] ?? null) : null,
                        ]);
                    })
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->getCurrentMember()?->id),
                EditAction::make()
                    ->label('Edit')
                    ->form($this->getNoteFormSchema())
                    ->action(function (WorkgroupNote $record, array $data): void {
                        $updateData = [
                            'title' => $data['title'],
                            'content' => $data['content'],
                            'is_shared' => $data['is_shared'] ?? false,
                            'shared_with_user_id' => ($data['is_shared'] ?? false) ? ($data['shared_with_user_id'] ?? null) : null,
                        ];
                        $record->update($updateData);
                    })
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->getCurrentMember()?->id),
                DeleteAction::make()
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->getCurrentMember()?->id),
            ])
            ->emptyStateHeading('No notes yet')
            ->emptyStateDescription('Create your first note to get started. You can also share notes with your workgroup members.');
    }

    protected function getNotesQuery(): Builder
    {
        $member = $this->getCurrentMember();

        if (!$member) {
            return WorkgroupNote::whereNull('id');
        }

        $userId = Auth::id();

        // Show: own notes + notes shared with me (directly or to everyone in my workgroup)
        $query = WorkgroupNote::where(function (Builder $q) use ($member, $userId) {
            // My own notes
            $q->where('workgroup_member_id', $member->id);

            // Notes shared with me specifically
            $q->orWhere(function (Builder $shared) use ($userId) {
                $shared->where('is_shared', true)
                    ->where('shared_with_user_id', $userId);
            });

            // Notes shared with everyone in my workgroup
            $q->orWhere(function (Builder $sharedAll) use ($member) {
                $sharedAll->where('is_shared', true)
                    ->whereNull('shared_with_user_id')
                    ->whereHas('member', fn (Builder $mq) => $mq->where('workgroup_id', $member->workgroup_id));
            });
        });

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
            'is_shared' => $data['is_shared'] ?? false,
            'shared_with_user_id' => ($data['is_shared'] ?? false) ? ($data['shared_with_user_id'] ?? null) : null,
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
