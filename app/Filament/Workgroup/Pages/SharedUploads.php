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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('File')
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
                        ->maxSize(10240),
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
        $session = $this->requireSelectedSession($member);

        $file = $data['file'] ?? null;

        if (! $file) {
            return;
        }

        // Filament FileUpload in action forms returns a string (temp storage path),
        // not an UploadedFile object. Handle both cases.
        if (is_array($file)) {
            $file = reset($file); // Get first element
        }

        // Sensitive: shared uploads must NOT be web-reachable.
        $privateDisk = config('filesystems.private', 'local');

        if (is_string($file)) {
            // Filament already stored the file in the default Livewire temp directory.
            // Move it to the permanent location on the private disk.
            $tempPath = $file; // e.g. "livewire-tmp/abc123.pdf"
            $filename = pathinfo($tempPath, PATHINFO_BASENAME);
            $extension = pathinfo($tempPath, PATHINFO_EXTENSION);
            $permanentDir = 'workgroup-shared-uploads/'.$member->workgroup_id;
            $permanentPath = $permanentDir.'/'.$filename;

            // Move from the Livewire temp (local) disk to the private disk
            $contents = Storage::disk('local')->get($tempPath);
            Storage::disk($privateDisk)->put($permanentPath, $contents);
            $fileSize = Storage::disk($privateDisk)->size($permanentPath);
            $mimeType = Storage::disk($privateDisk)->mimeType($permanentPath);

            // Clean up temp file
            Storage::disk('local')->delete($tempPath);

            WorkgroupSharedUpload::create([
                'workgroup_id' => $member->workgroup_id,
                'workgroup_session_id' => $session->id,
                'user_id' => $this->currentUser()->id,
                'workgroup_member_id' => $member->id,
                'filename' => $filename,
                'filepath' => $permanentPath,
                'file_type' => $mimeType ?: ('application/'.$extension),
                'file_size' => $fileSize,
            ]);
        } else {
            // UploadedFile object (fallback for direct uploads)
            $path = $file->store('workgroup-shared-uploads/'.$member->workgroup_id, $privateDisk);

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
