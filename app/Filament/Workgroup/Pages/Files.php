<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\WorkgroupFile;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSharedUpload;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class Files extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string $view = 'filament::pages.simple-page';

    protected static ?string $title = 'Files';
    
    protected static ?string $navigationLabel = 'Files';

    public ?string $activeTab = 'assigned';
    
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
                ->url(fn () => SharedUploads::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getFilesQuery())
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
                    ->url(fn ($record) => $this->getDownloadUrl($record)),
            ])
            ->emptyStateHeading('No files found');
    }

    protected function getFilesQuery(): Builder
    {
        $member = $this->getCurrentMember();
        
        if (!$member || !$member->workgroup) {
            return WorkgroupFile::whereNull('id');
        }

        $workgroupId = $member->workgroup->id;
        $sessionId = $this->selectedSession ? (int) $this->selectedSession : null;

        if ($this->activeTab === 'assigned') {
            return WorkgroupFile::where('workgroup_id', $workgroupId)
                ->when($sessionId, fn($q) => $q->where('workgroup_session_id', $sessionId));
        } else {
            return WorkgroupSharedUpload::where('workgroup_id', $workgroupId)
                ->when($sessionId, fn($q) => $q->where('workgroup_session_id', $sessionId));
        }
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        $user = Auth::user();
        
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }

    protected function getDownloadUrl($record): string
    {
        if ($record instanceof WorkgroupFile) {
            return route('workgroup.file.download', $record);
        } elseif ($record instanceof WorkgroupSharedUpload) {
            return route('workgroup.shared-upload.download', $record);
        }
        
        return '#';
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
