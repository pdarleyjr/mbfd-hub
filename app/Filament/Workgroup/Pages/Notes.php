<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupNote;
use App\Models\WorkgroupSession;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
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
        $member = $this->currentMember();
        $activeSession = WorkgroupSession::query()
            ->where('workgroup_id', $member->workgroup_id)
            ->active()
            ->first();

        if ($activeSession) {
            $this->selectedSession = (string) $activeSession->id;
        }
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && app(WorkgroupAccess::class)->canEnterPanel($user)
            && app(WorkgroupContext::class)->member($user) !== null;
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
        $member = $this->currentMember();
        $workgroupId = $member->workgroup_id;

        // Get other members in same workgroup for share-with-specific-user dropdown
        $memberOptions = WorkgroupMember::where('workgroup_id', $workgroupId)
            ->where('is_active', true)
            ->where('id', '!=', $member->id)
            ->with('user')
            ->get()
            ->mapWithKeys(fn ($otherMember) => [$otherMember->user_id => $otherMember->user?->name ?? "Member #{$otherMember->id}"])
            ->toArray();

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
                        if (! $record->is_shared) {
                            return '—';
                        }

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
                        $member = $this->currentMember();
                        $memberOptions = WorkgroupMember::where('workgroup_id', $member->workgroup_id)
                            ->where('is_active', true)
                            ->where('id', '!=', $member->id)
                            ->with('user')
                            ->get()
                            ->mapWithKeys(fn ($otherMember) => [$otherMember->user_id => $otherMember->user?->name ?? "Member #{$otherMember->id}"])
                            ->toArray();

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
                        $member = $this->currentMember();

                        $this->ownedNote($record, $member)->update($this->sharingAttributes($data, $member));
                    })
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->currentMember()->id),
                EditAction::make()
                    ->label('Edit')
                    ->form($this->getNoteFormSchema())
                    ->action(function (WorkgroupNote $record, array $data): void {
                        $member = $this->currentMember();
                        $updateData = [
                            'title' => $data['title'],
                            'content' => $data['content'],
                        ];

                        $this->ownedNote($record, $member)->update([
                            ...$updateData,
                            ...$this->sharingAttributes($data, $member),
                        ]);
                    })
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->currentMember()->id),
                DeleteAction::make()
                    ->action(function (WorkgroupNote $record): void {
                        $this->ownedNote($record, $this->currentMember())->delete();
                    })
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->currentMember()->id),
            ])
            ->emptyStateHeading('No notes yet')
            ->emptyStateDescription('Create your first note to get started. You can also share notes with your workgroup members.');
    }

    protected function getNotesQuery(): Builder
    {
        $member = $this->currentMember();
        $userId = $this->currentUser()->id;
        $selectedSession = $this->selectedSession($member);

        // Show: own notes + notes shared with me (directly or to everyone in my workgroup)
        $query = $this->notesForCurrentWorkgroup($member)->where(function (Builder $q) use ($member, $userId) {
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

        if ($selectedSession) {
            $query->where('workgroup_session_id', $selectedSession->id);
        }

        return $query->orderBy('created_at', 'desc');
    }

    protected function createNote(array $data): void
    {
        $member = $this->currentMember();
        $selectedSession = $this->selectedSession($member);

        WorkgroupNote::create([
            'workgroup_member_id' => $member->id,
            'workgroup_session_id' => $selectedSession?->id,
            'title' => $data['title'],
            'content' => $data['content'],
            ...$this->sharingAttributes($data, $member),
        ]);
    }

    public function updatedActiveTab(): void
    {
        $this->dispatch('$refresh');
    }

    public function updatedSelectedSession(): void
    {
        $this->selectedSession($this->currentMember());

        $this->dispatch('$refresh');
    }

    private function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 404);

        return $user;
    }

    private function currentMember(): WorkgroupMember
    {
        $user = $this->currentUser();

        abort_unless(app(WorkgroupAccess::class)->canEnterPanel($user), 404);

        return app(WorkgroupContext::class)->requireMember($user);
    }

    private function selectedSession(WorkgroupMember $member): ?WorkgroupSession
    {
        if ($this->selectedSession === null || $this->selectedSession === '') {
            return null;
        }

        abort_unless(ctype_digit($this->selectedSession) && (int) $this->selectedSession > 0, 404);

        $session = app(WorkgroupAccess::class)
            ->scopeSessions(WorkgroupSession::query(), $this->currentUser())
            ->where('workgroup_id', $member->workgroup_id)
            ->find((int) $this->selectedSession);

        abort_unless($session !== null, 404);

        return $session;
    }

    /** @return Builder<WorkgroupNote> */
    private function notesForCurrentWorkgroup(WorkgroupMember $member): Builder
    {
        return WorkgroupNote::query()
            ->whereHas('member', fn (Builder $members): Builder => $members->where('workgroup_id', $member->workgroup_id))
            ->where(function (Builder $notes) use ($member): void {
                $notes->whereNull('workgroup_session_id')
                    ->orWhereHas('session', fn (Builder $sessions): Builder => $sessions->where('workgroup_id', $member->workgroup_id));
            });
    }

    private function ownedNote(WorkgroupNote $record, WorkgroupMember $member): WorkgroupNote
    {
        $note = $this->notesForCurrentWorkgroup($member)
            ->where('workgroup_member_id', $member->id)
            ->find($record->getKey());

        abort_unless($note !== null, 404);

        return $note;
    }

    /** @param array<string, mixed> $data */
    private function sharingAttributes(array $data, WorkgroupMember $member): array
    {
        $isShared = $this->sharedFlag($data);

        return [
            'is_shared' => $isShared,
            'shared_with_user_id' => $isShared ? $this->sharedWithUserId($data, $member) : null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function sharedFlag(array $data): bool
    {
        return match ($data['is_shared'] ?? false) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false', '' => false,
            default => abort(404),
        };
    }

    /** @param array<string, mixed> $data */
    private function sharedWithUserId(array $data, WorkgroupMember $member): ?int
    {
        $recipientId = $data['shared_with_user_id'] ?? null;

        if ($recipientId === null || $recipientId === '') {
            return null;
        }

        abort_unless(
            (is_int($recipientId) || (is_string($recipientId) && ctype_digit($recipientId)))
                && (int) $recipientId > 0,
            404,
        );

        $recipientIsActiveInCurrentWorkgroup = WorkgroupMember::query()
            ->where('workgroup_id', $member->workgroup_id)
            ->where('user_id', (int) $recipientId)
            ->where('is_active', true)
            ->where('id', '!=', $member->id)
            ->exists();

        abort_unless($recipientIsActiveInCurrentWorkgroup, 404);

        return (int) $recipientId;
    }
}
