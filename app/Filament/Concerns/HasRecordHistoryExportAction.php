<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Exports\ExportDataset;
use App\Services\Exports\ExportFailedException;
use App\Services\Exports\RecordHistoryDatasets;
use App\Services\Exports\TableExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** Export only the histories this native record page admits the current actor to read. */
trait HasRecordHistoryExportAction
{
    /** @return array<string, string> */
    protected function recordHistoryOptions(): array
    {
        return app(RecordHistoryDatasets::class)->sections($this->getRecord());
    }

    public function canExportRecordHistory(): bool
    {
        $resource = static::getResource();

        return auth()->user() instanceof User && $resource::canViewAny()
            && ($this instanceof EditRecord ? $resource::canEdit($this->getRecord()) : $resource::canView($this->getRecord()));
    }

    protected function recordHistoryExportAction(): Action
    {
        return Action::make('exportRecordHistory')->label('Export record tables')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (): bool => $this->canExportRecordHistory())
            ->form([
                Select::make('section')->label('Table')->options(fn (): array => $this->recordHistoryOptions())->required(),
                Radio::make('format')->label('File type')->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF'])->default('xlsx')->required(),
            ])->modalSubmitActionLabel('Download')
            ->action(function (array $data, Action $action) {
                try {
                    return app(TableExportService::class)->downloadDataset(
                        auth()->user(), static::class, (string) ($data['format'] ?? ''),
                        fn (): bool => $this->canExportRecordHistory() && isset($this->recordHistoryOptions()[$data['section'] ?? '']),
                        fn (): ExportDataset => app(RecordHistoryDatasets::class)->build(auth()->user(), $this->getRecord(), (string) $data['section']),
                        'record history',
                    );
                } catch (ExportFailedException $exception) {
                    Notification::make()->title('Export not created')->body($exception->getMessage())->danger()->send();
                    $action->halt();

                    return null;
                }
            });
    }
}
