<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\RelationManagers;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Mbfd\PolicyLibrary\Support\LibraryAccess;

final class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'PDF Revisions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof ManualNode && LibraryAccess::canManage(auth('web')->user()) && $ownerRecord->type === 'document';
    }

    public function getOwnerRecord(): ManualNode
    {
        $record = parent::getOwnerRecord();
        if (! $record instanceof ManualNode) {
            throw new \LogicException('Navigation entry not found.');
        }

        return $record;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('version_label')->label('Version'),
            Tables\Columns\TextColumn::make('revision_date')->date()->label('Revision date'),
            Tables\Columns\TextColumn::make('page_count')->label('Pages'),
            Tables\Columns\TextColumn::make('state')->badge(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->label('Uploaded'),
            Tables\Columns\TextColumn::make('published_at')->dateTime(),
        ])->defaultSort('created_at', 'desc')->headerActions([
            Tables\Actions\Action::make('upload')->label('Upload PDF')->icon('heroicon-o-arrow-up-tray')->form([
                $this->pdfUpload(), Forms\Components\TextInput::make('version_label')->maxLength(255),
                Forms\Components\DatePicker::make('revision_date'), Forms\Components\Textarea::make('revision_notes')->maxLength(4000),
            ])->action(function (array $data): void {
                $this->authorizeManager();
                $file = $this->uploadedFile($data);
                app(RevisionService::class)->createDraft($this->getOwnerRecord(), $file->getRealPath(), $file->getClientOriginalName(), auth('web')->id(), array_intersect_key($data, array_flip(['version_label', 'revision_date', 'revision_notes'])));
                Notification::make()->title('Draft uploaded. Preview before publishing.')->success()->send();
            }),
        ])->actions([
            Tables\Actions\Action::make('preview')->url(fn (DocumentRevision $record) => route('policy-library.preview', ['uuid' => $record->uuid]))->openUrlInNewTab()->icon('heroicon-o-eye'),
            Tables\Actions\Action::make('publish')->label(fn (DocumentRevision $record) => $record->state === 'archived' ? 'Roll back' : 'Publish')->requiresConfirmation()->modalDescription('This switches the current document. The previous PDF remains in revision history.')
                ->visible(fn (DocumentRevision $record) => $record->id !== $this->getOwnerRecord()->current_revision_id)->action(function (DocumentRevision $record): void {
                    $this->authorizeManager();
                    app(RevisionService::class)->publish($record, auth('web')->id());
                    Notification::make()->title('Current revision updated.')->success()->send();
                }),
            Tables\Actions\Action::make('replace_pages')->label('Replace pages')->visible(fn (DocumentRevision $record) => $record->id === $this->getOwnerRecord()->current_revision_id)->form([
                Forms\Components\TextInput::make('start')->label('First page to replace')->integer()->minValue(1)->required(),
                Forms\Components\TextInput::make('end')->label('Last page to replace')->integer()->minValue(1)->required(), $this->pdfUpload(),
            ])->action(function (DocumentRevision $record, array $data): void {
                $this->authorizeManager();
                $file = $this->uploadedFile($data);
                app(RevisionService::class)->replaceRange($record, (int) $data['start'], (int) $data['end'], $file->getRealPath(), $file->getClientOriginalName(), auth('web')->id());
                Notification::make()->title('Replacement draft created. Preview before publishing.')->success()->send();
            }),
        ]);
    }

    private function pdfUpload(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('pdf')->label('PDF document')->acceptedFileTypes(['application/pdf'])->maxSize(config('policy-library.max_upload_kb'))->storeFiles(false)->required();
    }

    private function uploadedFile(array $data): UploadedFile
    {
        $file = $data['pdf'] ?? null;
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['pdf' => 'Upload a PDF document.']);
        }

        return $file;
    }

    private function authorizeManager(): void
    {
        abort_unless(LibraryAccess::canManage(auth('web')->user()), 403);
    }
}
