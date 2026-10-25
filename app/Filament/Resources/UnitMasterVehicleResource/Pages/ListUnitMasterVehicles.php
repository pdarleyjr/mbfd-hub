<?php

namespace App\Filament\Resources\UnitMasterVehicleResource\Pages;

use App\Filament\Resources\UnitMasterVehicleResource;
use App\Models\UnitMasterVehicle;
use App\Services\Fleet\UnitMasterRosterExporter;
use Filament\Actions;
use Filament\Forms\Components\Radio;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListUnitMasterVehicles extends ListRecords
{
    protected static string $resource = UnitMasterVehicleResource::class;

    /** The inventory is a wide table; let it use the whole content area. */
    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    /**
     * "Apparatus review": master records in the fire apparatus section with no apparatus row yet.
     * Fleet creates the apparatus record or marks the vehicle "not an apparatus".
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All vehicles'),
            'apparatus_review' => Tab::make('Apparatus review')
                ->modifyQueryUsing(fn (Builder $query): Builder => UnitMasterVehicle::constrainToApparatusReview($query))
                ->badge(fn (): int => UnitMasterVehicle::query()->needingApparatusReview()->count())
                ->badgeColor('warning'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction('exportExcel', 'Export Excel', 'heroicon-o-table-cells', 'xlsx'),
            $this->exportAction('exportPdf', 'Export PDF', 'heroicon-o-document-arrow-down', 'pdf'),
            Actions\CreateAction::make(),
            Actions\Action::make('importReview')->label('Historical document review')
                ->icon('heroicon-o-inbox')->color('gray')
                ->visible(fn (): bool => \App\Support\Fleet\VehicleDocumentAccess::canManage(auth()->user()))
                ->url(UnitMasterVehicleResource::getUrl('import-review')),
        ];
    }

    private function exportAction(string $name, string $label, string $icon, string $format): Actions\Action
    {
        return Actions\Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->modalHeading($label)
            ->modalSubmitActionLabel('Download')
            ->form([
                Radio::make('scope')
                    ->label('Records to export')
                    ->options(fn (): array => [
                        'filtered' => 'Current filters & search ('.number_format($this->filteredCount()).' vehicles)',
                        'all' => 'All vehicles ('.number_format(UnitMasterVehicle::query()->count()).')',
                    ])
                    ->default('filtered')
                    ->required(),
            ])
            ->action(fn (array $data): StreamedResponse => $this->streamRoster($format, ($data['scope'] ?? 'filtered') === 'all'));
    }

    private function streamRoster(string $format, bool $all): StreamedResponse
    {
        $exporter = app(UnitMasterRosterExporter::class);
        $generatedAt = now(UnitMasterRosterExporter::TIMEZONE);
        $scope = $all ? 'All records' : 'Current filters & search';
        $filters = $all ? 'None (all records)' : $this->describeFilters();

        $bytes = $format === 'xlsx'
            ? $exporter->xlsx($this->rosterVehicles($all), $scope, $filters, $generatedAt)
            : $exporter->pdf($this->rosterVehicles($all), $scope, $filters, $generatedAt);

        $contentType = $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/pdf';

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            $exporter->filename($format, $generatedAt),
            ['Content-Type' => $contentType, 'Cache-Control' => 'private, no-store'],
        );
    }

    /** Stable order (vehicle number, then id) so repeated exports match. */
    private function rosterVehicles(bool $all): Collection
    {
        $query = $all ? UnitMasterVehicle::query() : $this->getFilteredTableQuery();

        return $query->reorder()->orderBy('veh_number')->orderBy('id')->get();
    }

    private function filteredCount(): int
    {
        return $this->getFilteredTableQuery()->count();
    }

    /** Human description of the active table search and filters, "None" when empty. */
    private function describeFilters(): string
    {
        $parts = [];

        if ($this->activeTab === 'apparatus_review') {
            $parts[] = 'Apparatus review list';
        }

        $search = trim((string) $this->tableSearch);
        if ($search !== '') {
            $parts[] = 'Search: "'.$search.'"';
        }

        foreach ($this->getTable()->getFilters() as $name => $filter) {
            $state = $this->tableFilters[$name] ?? [];
            if ($filter instanceof SelectFilter) {
                $value = $state['value'] ?? null;
                if (blank($value)) {
                    continue;
                }
                $parts[] = $filter->getLabel().': '.($filter->getOptions()[$value] ?? $value);
            } elseif (! empty($state['isActive'])) {
                $parts[] = $filter->getLabel();
            }
        }

        return $parts === [] ? 'None' : implode('; ', $parts);
    }
}
