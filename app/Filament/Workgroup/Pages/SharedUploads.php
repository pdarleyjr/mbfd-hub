<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class SharedUploads extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static string $view = 'filament-workgroup.pages.simple-page';

    protected static ?string $title = 'Shared Uploads';

    protected static ?string $navigationLabel = 'Shared Uploads';

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
            Action::make('uploadFile')
                ->label('Upload File')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->form([
                    \Filament\Forms\Components\Select::make('workgroup_session_id')
                        ->label('Session')
                        ->options(fn () => app(WorkgroupAccess::class)
                            ->scopeSessions(WorkgroupSession::query(), $this->currentUser())
                            ->where('workgroup_id', $this->currentMember()->workgroup_id)
                            ->orderByDesc('start_date')
                            ->pluck('name', 'id'))
                        ->default(fn () => $this->selectedSession)
                        ->required(),
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('File')
                        ->disk(fn (): string => (string) config('filesystems.private', 'local'))
                        ->visibility('private')
                        ->storeFiles(false)
                        ->required()
                        ->maxFiles(1)
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'image/*',
                        ])
                        ->maxSize(51200)
                        ->helperText('PDF, Office documents, and images up to 50 MB.'),
                ])
                ->action(function (array $data): void {
                    $this->uploadFile($data);
                })
                ->modalSubmitActionLabel('Upload'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getUploadsQuery())
            ->columns([
                TextColumn::make('filename')
                    ->label('Filename')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('file_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('formatted_size')
                    ->label('Size'),

                TextColumn::make('user.name')
                    ->label('Uploaded By'),

                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (WorkgroupSharedUpload $record) => route('workgroup.shared-upload.download', $record)),
                DeleteAction::make()
                    ->label('Delete')
                    ->action(function (WorkgroupSharedUpload $record): void {
                        $this->ownedUpload($record, $this->currentMember())->delete();
                    })
                    ->visible(fn (WorkgroupSharedUpload $record) => $this->isCurrentUploader($record)),
            ])
            ->emptyStateHeading('No files uploaded yet')
            ->emptyStateDescription('Upload a file to share with your workgroup.');
    }

    protected function getUploadsQuery(): Builder
    {
        $member = $this->currentMember();
        $selectedSession = $this->selectedSession($member);

        return app(WorkgroupAccess::class)
            ->scopeWorkgroupRecords(WorkgroupSharedUpload::query(), $this->currentUser())
            ->where('workgroup_id', $member->workgroup_id)
            ->whereHas('session', fn (Builder $sessions): Builder => $sessions->where('workgroup_id', $member->workgroup_id))
            ->when($selectedSession, fn (Builder $uploads): Builder => $uploads->where('workgroup_session_id', $selectedSession->id))
            ->orderBy('created_at', 'desc');
    }

    protected function uploadFile(array $data): void
    {
        $member = $this->currentMember();
        if (array_key_exists('workgroup_session_id', $data)) {
            $this->selectedSession = (string) $data['workgroup_session_id'];
        }
        $session = $this->requireSelectedSession($member);

        $file = $data['file'] ?? null;

        if (is_array($file)) {
            $file = reset($file);
        }

        abort_unless($file instanceof UploadedFile, 422);

        $privateDisk = config('filesystems.private', 'local');
        $path = $file->store('workgroup-shared-uploads/'.$member->workgroup_id, $privateDisk);
        abort_unless(is_string($path) && $path !== '', 500, 'Unable to store the uploaded file.');

        WorkgroupSharedUpload::create([
            'workgroup_id' => $member->workgroup_id,
            'workgroup_session_id' => $session->id,
            'user_id' => $this->currentUser()->id,
            'workgroup_member_id' => $member->id,
            'filename' => $file->getClientOriginalName(),
            'filepath' => $path,
            'file_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
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

        if (! ($session instanceof WorkgroupSession)) {
            abort(404);
        }

        return $session;
    }

    private function requireSelectedSession(WorkgroupMember $member): WorkgroupSession
    {
        $session = $this->selectedSession($member);

        abort_unless($session !== null, 404);

        return $session;
    }

    private function isCurrentUploader(WorkgroupSharedUpload $record): bool
    {
        $member = $this->currentMember();

        return $record->workgroup_id === $member->workgroup_id
            && $record->workgroup_member_id === $member->id
            && $record->user_id === $this->currentUser()->id;
    }

    private function ownedUpload(WorkgroupSharedUpload $record, WorkgroupMember $member): WorkgroupSharedUpload
    {
        $upload = app(WorkgroupAccess::class)
            ->scopeWorkgroupRecords(WorkgroupSharedUpload::query(), $this->currentUser())
            ->where('workgroup_id', $member->workgroup_id)
            ->where('workgroup_member_id', $member->id)
            ->where('user_id', $this->currentUser()->id)
            ->whereHas('session', fn (Builder $sessions): Builder => $sessions->where('workgroup_id', $member->workgroup_id))
            ->find($record->getKey());

        if (! ($upload instanceof WorkgroupSharedUpload)) {
            abort(404);
        }

        return $upload;
    }
}
