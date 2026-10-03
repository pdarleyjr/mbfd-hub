<?php

namespace App\Filament\Resources\OperationalFormRecordResource\Pages;

use App\Filament\Resources\OperationalFormRecordResource;
use App\Models\OperationalFormRecord;
use App\Services\OperationalEvidenceArchiveService;
use App\Services\OperationalForms\OperationalFormDeletionService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationalFormRecord extends ViewRecord
{
    protected static string $resource = OperationalFormRecordResource::class;

    protected static string $view = 'filament.resources.operational-form-record.view';

    public function getRecord(): OperationalFormRecord
    {
        /** @var OperationalFormRecord $record */
        $record = parent::getRecord();

        return $record;
    }

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
                ->form([\Filament\Forms\Components\Textarea::make('archive_reason')->label('Reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => OperationalFormRecordResource::canDelete($this->getRecord()) && ! $this->getRecord()->isArchived() && ! $this->getRecord()->trashed())
                ->action(fn (array $data) => app(OperationalEvidenceArchiveService::class)->archive($this->getRecord(), auth()->user(), $data['archive_reason'] ?? null)),
            Actions\Action::make('restore')
                ->label('Restore')
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->visible(fn (): bool => OperationalFormRecordResource::canDelete($this->getRecord()) && ($this->getRecord()->isArchived() || $this->getRecord()->trashed()))
                ->action(fn () => app(OperationalEvidenceArchiveService::class)->restore($this->getRecord(), auth()->user())),
            Actions\Action::make('delete')
                ->label('Move to Trash')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Move this form to Trash?')
                ->modalDescription('Documents and version history are retained. Use the Trash filter to find and restore this form.')
                ->visible(fn (): bool => OperationalFormRecordResource::canDelete($this->getRecord()) && ! $this->getRecord()->trashed())
                ->action(function (): void {
                    app(OperationalFormDeletionService::class)->deleteRecord($this->getRecord(), auth()->user());
                    $this->redirect(OperationalFormRecordResource::getUrl('index'));
                }),
        ];
    }
}
