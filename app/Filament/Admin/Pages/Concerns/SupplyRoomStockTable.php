<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Filament\Support\EmsLabels;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Services\Ems\StockLedger;
use App\Services\Ems\SupplyItemMaintenance;
use App\Services\Ems\SupplyRoomQuery;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\View;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Stock" tab of the Medical Supply Room: one row per tracked supply room item with what is on
 * hand and how we know it, plus the item settings modal (provenance for everyone, edits for people
 * who may configure). Needs RunsEmsActions on the host class.
 */
trait SupplyRoomStockTable
{
    private const PACKAGE_UNITS = ['each' => 'Each', 'box' => 'Box', 'case' => 'Case', 'pack' => 'Pack', 'bag' => 'Bag'];

    /** @var array<int, array<string, mixed>>|null current quantity and basis per item, for this request only */
    private ?array $currentMemo = null;

    /** @return array<int, array<string, mixed>> */
    protected function currentStock(): array
    {
        return $this->currentMemo ??= app(StockLedger::class)->current();
    }

    protected function forgetCurrentStock(): void
    {
        $this->currentMemo = null;
    }

    /** @return array<string, mixed>|null */
    private function stockOf(SupplyItem $item): ?array
    {
        return $this->currentStock()[$item->getKey()] ?? null;
    }

    private function isBelow(SupplyItem $item): bool
    {
        $quantity = $this->stockOf($item)['quantity'] ?? null;

        return $quantity !== null && $item->threshold_qty_base !== null && $quantity < $item->threshold_qty_base;
    }

    /** @return list<int> */
    private function belowThresholdIds(): array
    {
        return SupplyItem::query()->where('active', true)->where('controlled_substance', false)->whereNotNull('threshold_qty_base')
            ->get(['id', 'threshold_qty_base'])->filter(fn (SupplyItem $item): bool => $this->isBelow($item))->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SupplyItem::query()->with('location:id,code,label')->where('active', true)->where('controlled_substance', false))
            ->heading('Current stock')
            ->description('What is on hand and how we know it. Calculated means a count plus the movements since, not a new count.')
            ->defaultSort('name')
            ->paginated([25, 50, 100])->defaultPaginationPageOption(50)
            ->emptyStateHeading('No supply room items yet')
            ->emptyStateDescription('Items appear when the Captain 5 supply room sheet has been imported.')
            ->columns([
                Tables\Columns\TextColumn::make('location.code')->label('Location'),
                Tables\Columns\TextColumn::make('name')->label('Item')->searchable(['name', 'bag_alias_text'])->sortable()->wrap()
                    ->extraHeaderAttributes(['style' => 'min-width: 18rem'])
                    ->description(fn (SupplyItem $record): ?string => filled($record->bag_alias_text) ? 'Also called: '.$record->bag_alias_text : null),
                Tables\Columns\TextColumn::make('on_hand')->label('On hand')->alignEnd()
                    ->state(fn (SupplyItem $record): string => ($quantity = $this->stockOf($record)['quantity'] ?? null) === null ? 'Not counted' : number_format($quantity).' '.$record->base_unit)
                    ->description(fn (SupplyItem $record): ?string => ($this->stockOf($record)['quantity'] ?? null) === null ? null : ($this->stockOf($record)['basis_label'] ?? null))
                    ->color(fn (SupplyItem $record): ?string => $this->isBelow($record) ? 'danger' : null)
                    ->weight(fn (SupplyItem $record) => $this->isBelow($record) ? 'bold' : null),
                Tables\Columns\TextColumn::make('level')->label('Level')->badge()
                    ->state(fn (SupplyItem $record): string => match (true) {
                        ($this->stockOf($record)['quantity'] ?? null) === null => 'Not counted yet',
                        $record->threshold_qty_base === null => 'No threshold set',
                        $this->isBelow($record) => 'Below threshold',
                        default => 'Stocked',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Below threshold' => 'danger', 'Stocked' => 'success', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('threshold')->label('Threshold')->alignEnd()
                    ->state(fn (SupplyItem $record): string => $record->threshold_qty_base === null ? 'Not set' : number_format($record->threshold_qty_base).' '.$record->base_unit)
                    ->description(fn (SupplyItem $record): ?string => filled($record->threshold_source_text) ? 'Original: '.$record->threshold_source_text : null),
                Tables\Columns\TextColumn::make('package')->label('Package')
                    ->state(fn (SupplyItem $record): string => EmsLabels::packageText($record->package_unit, $record->units_per_package, $record->packages_per_case))
                    ->description(fn (SupplyItem $record): ?string => filled($record->qty_per_package_text) ? 'Sheet says: '.$record->qty_per_package_text : null),
                Tables\Columns\TextColumn::make('flags')->label('Flags')->badge()
                    ->state(fn (SupplyItem $record): array => array_values(array_filter([
                        $record->refrigerated ? 'Refrigerated' : null,
                        $record->needs_review ? 'Needs review' : null,
                    ])))
                    ->color(fn (string $state): string => $state === 'Needs review' ? 'warning' : 'info'),
            ])
            ->filters([
                SelectFilter::make('location')->label('Location')
                    ->options(fn (): array => StorageLocation::query()->where('active', true)->orderBy('sort')->orderBy('code')->get()
                        ->mapWithKeys(fn (StorageLocation $l): array => [$l->code => $l->code.(filled($l->label) ? ' - '.$l->label : '')])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('location', fn (Builder $l) => $l->where('code', (string) $data['value']))
                        : $query),
                Filter::make('below_threshold')->label('Below threshold')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('ems_supply_items.id', $this->belowThresholdIds())),
                Filter::make('needs_review')->label('Needs review')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('needs_review', true)),
            ])
            ->actions([$this->settingsAction()]);
    }

    private function settingsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('settings')->label('Details')->icon('heroicon-o-document-magnifying-glass')->color('gray')
            ->slideOver()
            ->modalHeading(fn (SupplyItem $record): string => $record->name)
            ->modalDescription(fn (): string => $this->emsCan('configure')
                ? 'Change the threshold, package size and location. Every change needs a reason and stays in the audit trail below.'
                : 'Where this row came from and how it is set up. Only an EMS manager can change it.')
            ->modalSubmitAction(fn ($action) => $this->emsCan('configure') ? $action : false)
            ->modalSubmitActionLabel('Save changes')
            ->modalCancelActionLabel('Close')
            ->fillForm(fn (SupplyItem $record): array => [
                'threshold_qty_base' => $record->threshold_qty_base, 'units_per_package' => $record->units_per_package,
                'packages_per_case' => $record->packages_per_case, 'package_unit' => $record->package_unit,
                'order_qty_packages' => $record->order_qty_packages, 'location_code' => $record->location?->code,
                'resolve_review' => false, 'reason' => null,
            ])
            ->form(fn (): array => $this->settingsForm())
            ->action(function (array $data, SupplyItem $record, Tables\Actions\Action $action): void {
                $input = [
                    'reason' => trim((string) ($data['reason'] ?? '')),
                    'threshold_qty_base' => $this->blankToNull($data['threshold_qty_base'] ?? null),
                    'units_per_package' => $this->blankToNull($data['units_per_package'] ?? null),
                    'packages_per_case' => $this->blankToNull($data['packages_per_case'] ?? null),
                    'order_qty_packages' => $this->blankToNull($data['order_qty_packages'] ?? null),
                    'package_unit' => filled($data['package_unit'] ?? null) ? (string) $data['package_unit'] : null,
                    'location_code' => filled($data['location_code'] ?? null) ? (string) $data['location_code'] : null,
                ];
                if (! empty($data['resolve_review'])) {
                    $input['resolve_review'] = true;
                }
                $this->emsRun($action, 'configure', fn ($user) => app(SupplyItemMaintenance::class)->update((int) $record->getKey(), $user, $this->emsKey($data), $input), 'Item settings saved', 'The change and your reason are in the audit trail.');
                $this->forgetCurrentStock();
            });
    }

    /** @return list<\Filament\Forms\Components\Component> */
    private function settingsForm(): array
    {
        $configure = $this->emsCan('configure');

        return [
            View::make('filament.admin.pages.partials.ems-item-provenance')
                ->viewData(fn (?SupplyItem $record): array => ['item' => $record === null ? [] : app(SupplyRoomQuery::class)->item($record)]),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('threshold_qty_base')->label('Reorder threshold (units)')->numeric()->integer()->minValue(0)->maxValue(10000000)->disabled(! $configure)
                    ->helperText('Leave empty if the threshold is not known. Do not guess.'),
                TextInput::make('units_per_package')->label('Units in one package')->numeric()->integer()->minValue(1)->maxValue(1000000)->disabled(! $configure)
                    ->helperText('Only fill this in when the box or case says so.'),
                Select::make('package_unit')->label('Package')->options(self::PACKAGE_UNITS)->placeholder('Not stated')->disabled(! $configure),
                TextInput::make('packages_per_case')->label('Packages in one case')->numeric()->integer()->minValue(1)->maxValue(1000000)->disabled(! $configure),
                TextInput::make('order_qty_packages')->label('Usual order (packages)')->numeric()->integer()->minValue(1)->maxValue(1000000)->disabled(! $configure),
                Select::make('location_code')->label('Location')->placeholder('No location')->disabled(! $configure)
                    ->options(fn (): array => StorageLocation::query()->where('active', true)->orderBy('sort')->orderBy('code')->pluck('code', 'code')->all()),
            ]),
            Toggle::make('resolve_review')->label('The review flag is resolved')->disabled(! $configure)
                ->visible(fn (?SupplyItem $record): bool => $configure && (bool) $record?->needs_review),
            Textarea::make('reason')->label('Reason for the change')->rows(2)->maxLength(500)->required()->visible($configure),
        ];
    }

    private function blankToNull(mixed $value): int|string|null
    {
        return $value === null || $value === '' ? null : (is_numeric($value) ? (int) $value : (string) $value);
    }
}
