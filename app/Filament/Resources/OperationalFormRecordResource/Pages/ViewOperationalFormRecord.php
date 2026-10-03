<?php

namespace App\Filament\Resources\OperationalFormRecordResource\Pages;

use App\Filament\Resources\OperationalFormRecordResource;
use App\Services\OperationalEvidenceArchiveService;
use App\Services\OperationalForms\OperationalFormDeletionService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationalFormRecord extends ViewRecord
{
    protected static string $resource = OperationalFormRecordResource::class;

    protected static string $view = 'filament.resources.operational-form-record.view';

    protected function resolveRecord($key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->load(['employee', 'documents']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('archive')
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->form([\Filament\Forms\Components\Textarea::make('archive_reason')->label('Reason (optional)')->maxLength(2000)])
                ->visible(fn (): bool => OperationalFormRecordResource::canDelete($this->record) && ! $this->record->isArchived() && ! $this->record->trashed())
                ->action(fn (array $data) => app(OperationalEvidenceArchiveService::class)->archive($this->record, auth()->user(), $data['archive_reason'] ?? null)),
            Actions\Action::make('restore')
                ->label('Restore')
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->visible(fn (): bool => OperationalFormRecordResource::canDelete($this->record) && ($this->record->isArchived() || $this->record->trashed()))
                ->action(fn () => app(OperationalEvidenceArchiveService::class)->restore($this->record, auth()->user())),
            Actions\Action::make('delete')
                ->label('Move to Trash')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Move this form to Trash?')
                ->modalDescription('Documents and version history are retained. Use the Trash filter to find and restore this form.')
                ->visible(fn (): bool => OperationalFormRecordResource::canDelete($this->record) && ! $this->record->trashed())
                ->action(function (): void {
                    app(OperationalFormDeletionService::class)->deleteRecord($this->record, auth()->user());
                    $this->redirect(OperationalFormRecordResource::getUrl('index'));
                }),
        ];
    }
}
