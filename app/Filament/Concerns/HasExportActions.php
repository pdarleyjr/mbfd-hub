<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Exports\ExportFailedException;
use App\Services\Exports\TableExportService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        foreach ($table->getColumns() as $column) {
            if (! $column->isToggleable()) {
                $column->toggleable();
            }
        }

        if ($table->getFilters() === []) {
            $table->filters([
                Filter::make('created_between')->label('Created between (Eastern)')
                    ->form([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Through')])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! self::validExportDateRange($data)) {
                            return $query->whereRaw('1 = 0');
                        }
                        $column = $query->getModel()->getQualifiedCreatedAtColumn();

                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->where($column, '>=', CarbonImmutable::parse($data['from'], TableExportService::TIMEZONE)->startOfDay()->utc()))
                            ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->where($column, '<', CarbonImmutable::parse($data['until'], TableExportService::TIMEZONE)->startOfDay()->addDay()->utc()));
                    })
                    ->indicateUsing(fn (array $data): array => ! self::validExportDateRange($data)
                        ? ['Created date range is invalid; choose valid dates in order.']
                        : array_values(array_filter([
                            filled($data['from'] ?? null) ? 'Created from '.$data['from'].' (Eastern)' : null,
                            filled($data['until'] ?? null) ? 'Created through '.$data['until'].' (Eastern)' : null,
                        ]))),
            ]);
        }

        return $table
            ->pushHeaderActions([static::makeExportHeaderAction()])
            ->pushBulkActions([static::makeExportBulkAction()]);
    }

    private static function validExportDateRange(array $data): bool
    {
        return Validator::make($data, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', Rule::when(filled($data['from'] ?? null), 'after_or_equal:from')],
        ])->passes();
    }

    public static function canExportTable(?HasTable $livewire = null): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(TableExportService::class)->canExport($user, static::class, $livewire);
    }

    public static function makeExportHeaderAction(): Action
    {
        $resource = static::class;

        return Action::make('export')
            ->label('Export')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (HasTable $livewire): bool => $resource::canExportTable($livewire))
            ->modalHeading(fn (HasTable $livewire): string => 'Export '.TableExportService::title($resource, $livewire))
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
            ->visible(fn (HasTable $livewire): bool => $resource::canExportTable($livewire))
            ->modalHeading(fn (HasTable $livewire): string => 'Export selected '.TableExportService::title($resource, $livewire))
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
