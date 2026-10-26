<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Exports\ExportDataset;
use App\Services\Exports\ExportFailedException;
use App\Services\Exports\TableExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;

/** Export the underlying values of a custom view without changing its data or lifecycle. */
trait HasDatasetExportActions
{
    abstract public function canExportData(User $actor): bool;

    abstract protected function exportDataset(User $actor): ExportDataset;

    protected function datasetExportAction(): Action
    {
        return Action::make('exportData')->label('Export current view')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (): bool => auth()->user() instanceof User && $this->canExportData(auth()->user()))
            ->form([Radio::make('format')->label('File type')->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF'])->default('xlsx')->required()])
            ->modalSubmitActionLabel('Download')
            ->action(function (array $data, Action $action) {
                try {
                    return app(TableExportService::class)->downloadDataset(
                        auth()->user(), static::class, (string) ($data['format'] ?? ''),
                        fn (): bool => $this->canExportData(auth()->user()),
                        fn (): ExportDataset => $this->exportDataset(auth()->user()),
                    );
                } catch (ExportFailedException $exception) {
                    Notification::make()->title('Export not created')->body($exception->getMessage())->danger()->send();
                    $action->halt();

                    return null;
                }
            });
    }
}
