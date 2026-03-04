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
use Filament\Notifications\Notification;
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
                ->form([
                    TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255),
                    RichEditor::make('content')
                        ->label('Content')
                        ->required(),
                    Toggle::make('is_shared')
                        ->label('Share with workgroup members')
                        ->default(false)
                        ->helperText('When enabled, all members of your workgroup can see this note.'),
                    Select::make('shared_with_user_id')
                        ->label('Share with specific member (optional)')
                        ->options(function () {
                            $member = $this->getCurrentMember();
                            if (!$member) return [];
                            return WorkgroupMember::where('workgroup_id', $member->workgroup_id)
                                ->where('id', '!=', $member->id)
                                ->where('is_active', true)
                                ->with('user')
                                ->get()
                                ->pluck('user.name', 'user.id')
                                ->toArray();
                        })
                        ->searchable()
                        ->nullable()
                        ->helperText('Share privately with a specific fellow workgroup member.'),
                ])
                ->action(function (array $data): void {
                    $member = $this->getCurrentMember();
                    if (!$member) {
                        Notification::make()->title('Error: No workgroup membership found.')->danger()->send();
                        return;
                    }

                    WorkgroupNote::create([
                        'workgroup_member_id' => $member->id,
                        'workgroup_session_id' => $this->selectedSession ? (int) $this->selectedSession : null,
                        'title' => $data['title'],
                        'content' => $data['content'],
                        'is_shared' => $data['is_shared'] ?? false,
                        'shared_with_user_id' => $data['shared_with_user_id'] ?? null,
                    ]);

                    Notification::make()->title('Note saved successfully!')->success()->send();
                })
                ->modalSubmitActionLabel('Save Note'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getNotesQuery())
            ->columns([
                TextColumn::make('title')->label('Title')->searchable()->sortable(),
                TextColumn::make('member.user.name')->label('Author')->sortable(),
                TextColumn::make('preview')->label('Preview')->limit(50),
                IconColumn::make('is_shared')->boolean()->label('Shared'),
                TextColumn::make('created_at')->label('Created')->dateTime('M j, Y g:i A')->sortable(),
            ])
            ->actions([
                EditAction::make()
                    ->label('Edit')
                    ->form([
                        TextInput::make('title')->label('Title')->required()->maxLength(255),
                        RichEditor::make('content')->label('Content')->required(),
                        Toggle::make('is_shared')
                            ->label('Share with workgroup members')
                            ->helperText('When enabled, all members can see this note.'),
                    ])
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->getCurrentMember()?->id),
                TableAction::make('share')
                    ->label('Share')
                    ->icon('heroicon-o-share')
                    ->color('info')
                    ->form([
                        Select::make('shared_with_user_id')
                            ->label('Share with member')
                            ->options(function () {
                                $member = $this->getCurrentMember();
                                if (!$member) return [];
                                return WorkgroupMember::where('workgroup_id', $member->workgroup_id)
                                    ->where('id', '!=', $member->id)
                                    ->where('is_active', true)
                                    ->with('user')
                                    ->get()
                                    ->pluck('user.name', 'user.id')
                                    ->toArray();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (WorkgroupNote $record, array $data): void {
                        $record->update([
                            'shared_with_user_id' => $data['shared_with_user_id'],
                            'is_shared' => true,
                        ]);
                        Notification::make()->title('Note shared successfully!')->success()->send();
                    })
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->getCurrentMember()?->id),
                DeleteAction::make()
                    ->visible(fn (WorkgroupNote $record) => $record->workgroup_member_id === $this->getCurrentMember()?->id),
            ])
            ->emptyStateHeading('No notes yet')
            ->emptyStateDescription('Create your first note using the "New Note" button above.');
    }

    protected function getNotesQuery(): Builder
    {
        $member = $this->getCurrentMember();
        if (!$member) {
            return WorkgroupNote::whereNull('id');
        }

        // Show: own notes + notes shared to workgroup + notes shared to me specifically
        return WorkgroupNote::where(function ($q) use ($member) {
            $q->where('workgroup_member_id', $member->id) // My own notes
              ->orWhere(function ($sub) use ($member) {
                  $sub->where('is_shared', true)
                      ->whereHas('member', fn($m) => $m->where('workgroup_id', $member->workgroup_id));
              })
              ->orWhere('shared_with_user_id', Auth::id()); // Shared specifically to me
        })->orderBy('created_at', 'desc');
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        return WorkgroupMember::where('user_id', Auth::id())
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }
}
