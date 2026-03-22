<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\WorkgroupMember;
use App\Models\WorkgroupSharedUpload;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
                    ->visible(fn (WorkgroupSharedUpload $record) => $record->user_id === Auth::id()),
            ])
            ->emptyStateHeading('No files uploaded yet')
            ->emptyStateDescription('Upload a file to share with your workgroup.');
    }

    protected function getUploadsQuery(): Builder
    {
        $member = $this->getCurrentMember();
        
        if (!$member || !$member->workgroup) {
            return WorkgroupSharedUpload::whereNull('id');
        }

        $workgroupId = $member->workgroup->id;
        $sessionId = $this->selectedSession ? (int) $this->selectedSession : null;

        return WorkgroupSharedUpload::where('workgroup_id', $workgroupId)
            ->when($sessionId, fn($q) => $q->where('workgroup_session_id', $sessionId))
            ->orderBy('created_at', 'desc');
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        $user = Auth::user();
        
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }

    protected function uploadFile(array $data): void
    {
        $member = $this->getCurrentMember();
        
        if (!$member || !$member->workgroup) {
            return;
        }

        $file = $data['file'] ?? null;
        
        if (!$file) {
            return;
        }

        // Filament FileUpload in action forms returns a string (temp storage path),
        // not an UploadedFile object. Handle both cases.
        if (is_array($file)) {
            $file = reset($file); // Get first element
        }

        if (is_string($file)) {
            // Filament already stored the file in the default Livewire temp directory.
            // Move it to the permanent location on the public disk.
            $tempPath = $file; // e.g. "livewire-tmp/abc123.pdf"
            $filename = pathinfo($tempPath, PATHINFO_BASENAME);
            $extension = pathinfo($tempPath, PATHINFO_EXTENSION);
            $permanentDir = 'workgroup-shared-uploads/' . $member->workgroup->id;
            $permanentPath = $permanentDir . '/' . $filename;

            // Move from default disk (local) to public disk
            $contents = Storage::disk('local')->get($tempPath);
            Storage::disk('public')->put($permanentPath, $contents);
            $fileSize = Storage::disk('public')->size($permanentPath);
            $mimeType = Storage::disk('public')->mimeType($permanentPath);

            // Clean up temp file
            Storage::disk('local')->delete($tempPath);

            WorkgroupSharedUpload::create([
                'workgroup_id' => $member->workgroup->id,
                'workgroup_session_id' => $this->selectedSession ? (int) $this->selectedSession : null,
                'user_id' => Auth::id(),
                'workgroup_member_id' => $member->id,
                'filename' => $filename,
                'filepath' => $permanentPath,
                'file_type' => $mimeType ?: ('application/' . $extension),
                'file_size' => $fileSize,
            ]);
        } else {
            // UploadedFile object (fallback for direct uploads)
            $path = $file->store('workgroup-shared-uploads/' . $member->workgroup->id, 'public');

            WorkgroupSharedUpload::create([
                'workgroup_id' => $member->workgroup->id,
                'workgroup_session_id' => $this->selectedSession ? (int) $this->selectedSession : null,
                'user_id' => Auth::id(),
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
        $this->dispatch('$refresh');
    }
}
