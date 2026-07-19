<?php

namespace App\Filament\Resources\OperationalFormRecordResource\Pages;

use App\Filament\Resources\OperationalFormRecordResource;
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
            Actions\Action::make('delete')
                ->label('Delete form')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete this form and all files?')
                ->modalDescription('This permanently deletes the record, every generated PDF or uploaded file, and its version history. This cannot be undone.')
                ->action(function (): void {
                    app(OperationalFormDeletionService::class)->deleteRecord($this->record);
                    $this->redirect(OperationalFormRecordResource::getUrl('index'));
                }),
        ];
    }
}
