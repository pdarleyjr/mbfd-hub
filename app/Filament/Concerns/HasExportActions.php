<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Exports\ExportFailedException;
use App\Services\Exports\TableExportService;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * One shared way to export a Filament resource's list table to Excel or PDF.
 *
 *   public static function table(Table $table): Table
 *   {
 *       return static::withExportActions($table->columns([...])->bulkActions([...]));
 *   }
 *
 * Call withExportActions() last: it appends an "Export" header action and an
 * "Export selected" bulk action without replacing the ones already defined.
 * The export uses the table's own visible columns, so anything hidden from the
 * signed-in user stays out of the file. A resource can name its own columns by
 * overriding exportColumns() (table column names and/or Column instances).
 * All work happens in App\Services\Exports\TableExportService.
 */
trait HasExportActions
{
    /**
     * Explicit export columns, or null to use the table's own visible columns.
     *
     * @return list<string|\Filament\Tables\Columns\Column>|null
     */
    public static function exportColumns(): ?array
    {
        return null;
    }

    public static function withExportActions(Table $table): Table
    {
        return $table
            ->pushHeaderActions([static::makeExportHeaderAction()])
            ->pushBulkActions([static::makeExportBulkAction()]);
    }

    public static function canExportTable(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(TableExportService::class)->canExport($user, static::class);
    }

    public static function makeExportHeaderAction(): Action
    {
        $resource = static::class;

        return Action::make('export')
            ->label('Export')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => $resource::canExportTable())
            ->modalHeading(fn (): string => 'Export '.TableExportService::title($resource))
            ->modalDescription('Downloads what the table shows, in the same column order. Hidden columns are left out.')
            ->modalSubmitActionLabel('Download')
            ->modalWidth('lg')
            ->form(fn (): array => [
                static::exportFormatInput(),
                Radio::make('scope')
                    ->label('Which records')
                    ->options([
                        'filtered' => 'Current view - the filters, search and sort now applied',
                        'all' => 'All records I can see - ignores filters and search; trashed records are left out',
                    ])
                    ->default('filtered')
                    ->required(),
                static::exportHiddenColumnsInput(),
            ])
            ->action(function (array $data, HasTable $livewire, Action $action) use ($resource) {
                return static::runExport($action, fn () => app(TableExportService::class)->download(
                    auth()->user(),
                    $livewire,
                    $resource,
                    (string) ($data['format'] ?? ''),
                    (string) ($data['scope'] ?? ''),
                    (bool) ($data['include_toggled_off'] ?? false),
                ));
            });
    }

    public static function makeExportBulkAction(): BulkAction
    {
        $resource = static::class;

        return BulkAction::make('export_selected')
            ->label('Export selected')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => $resource::canExportTable())
            ->modalHeading(fn (): string => 'Export selected '.TableExportService::title($resource))
            ->modalSubmitActionLabel('Download')
            ->modalWidth('lg')
            ->deselectRecordsAfterCompletion()
            ->fetchSelectedRecords(false)
            ->form(fn (): array => [static::exportFormatInput(), static::exportHiddenColumnsInput()])
            ->action(function (array $data, Collection $records, HasTable $livewire, BulkAction $action) use ($resource) {
                return static::runExport($action, fn () => app(TableExportService::class)->download(
                    auth()->user(),
                    $livewire,
                    $resource,
                    (string) ($data['format'] ?? ''),
                    'selected',
                    (bool) ($data['include_toggled_off'] ?? false),
                    $records->all(),
                ));
            });
    }

    protected static function exportFormatInput(): Radio
    {
        return Radio::make('format')
            ->label('File type')
            ->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF (landscape, for printing)'])
            ->default('xlsx')
            ->required();
    }

    protected static function exportHiddenColumnsInput(): Toggle
    {
        return Toggle::make('include_toggled_off')
            ->label('Also include columns turned off in the column picker')
            ->helperText('Columns you are not allowed to see are never exported.')
            ->default(false);
    }

    /** Run the export; a refusal (row cap, nothing selected) is shown to the user and keeps the dialog open. */
    protected static function runExport(Action|BulkAction $action, \Closure $download): mixed
    {
        try {
            return $download();
        } catch (ExportFailedException $exception) {
            Notification::make()->title('Export not created')->body($exception->getMessage())->danger()->send();
            $action->halt();

            return null;
        }
    }
}
