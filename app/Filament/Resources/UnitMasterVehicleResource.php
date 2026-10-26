<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\UnitMasterVehicleResource\ApparatusReviewActions;
use App\Filament\Resources\UnitMasterVehicleResource\Pages;
use App\Models\Employee;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnitMasterVehicleResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = UnitMasterVehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?string $navigationLabel = 'Master Vehicle Inventory';

    protected static ?string $modelLabel = 'Vehicle';

    protected static ?string $pluralModelLabel = 'Master Vehicle Inventory';

    protected static ?string $slug = 'unit-master-vehicles';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Vehicle Information')
                    ->description(fn (?UnitMasterVehicle $record): ?string => $record?->apparatus instanceof \App\Models\Apparatus
                        ? 'Vehicle #, make, model, year and VIN are shared with Apparatus '.($record->apparatus->designation ?: $record->apparatus->name).'. Saving here updates that unit too.'
                        : null)
                    ->schema([
                        Forms\Components\TextInput::make('veh_number')
                            ->label('Veh #')
                            ->nullable(),
                        Forms\Components\TextInput::make('tag_number')
                            ->label('License plate / Tag #')
                            ->nullable(),
                        Forms\Components\TextInput::make('make')
                            ->nullable(),
                        Forms\Components\TextInput::make('model')
                            ->nullable(),
                        Forms\Components\TextInput::make('year')
                            ->nullable(),
                        Forms\Components\TextInput::make('color')
                            ->nullable(),
                        Forms\Components\TextInput::make('serial_number')
                            ->label('VIN / Serial #')
                            ->nullable(),
                        Forms\Components\Select::make('asset_category')
                            ->label('Asset category')
                            ->options(UnitMasterVehicle::CATEGORY_LABELS)
                            ->nullable(),
                    ])->columns(3),

                Forms\Components\Section::make('Fleet Classification')
                    ->schema([
                        Forms\Components\TextInput::make('fleet_status_code')
                            ->label('FY27 status')
                            ->helperText('City FY27 status letter; meaning pending Fleet confirmation')
                            ->maxLength(16)
                            ->nullable(),
                        Forms\Components\TextInput::make('fleet_class_code')
                            ->label('Fleet class')
                            ->maxLength(32)
                            ->nullable(),
                        Forms\Components\TextInput::make('fleet_class_description')
                            ->label('Class description')
                            ->nullable(),
                        Forms\Components\Toggle::make('needs_fleet_review')
                            ->label('Needs Fleet review')
                            ->inline(false),
                    ])->columns(2),

                Forms\Components\Section::make('Department & Assignment')
                    ->schema([
                        Forms\Components\TextInput::make('section')
                            ->nullable(),
                        Forms\Components\TextInput::make('dept_code')
                            ->label('Dept.')
                            ->nullable(),
                        Forms\Components\TextInput::make('employee_or_vehicle_name')
                            ->label('Employee / Vehicle Name')
                            ->nullable(),
                        Forms\Components\TextInput::make('assignment')
                            ->label('Assignment (Driver)')
                            ->nullable(),
                        Forms\Components\Select::make('current_driver_employee_id')
                            ->label('Current driver')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::employeeOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => self::employeeLabel(Employee::query()->find($value)))
                            ->nullable(),
                        Forms\Components\TextInput::make('location')
                            ->label('Current Location')
                            ->nullable(),
                    ])->columns(2),

                Forms\Components\Section::make('Additional Details')
                    ->schema([
                        Forms\Components\TextInput::make('sunpass_number')
                            ->label('Sunpass #')
                            ->nullable(),
                        Forms\Components\TextInput::make('als_license')
                            ->label('ALS License')
                            ->nullable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['currentDriver', 'latestTakeHomeInspection', 'apparatus'])
                ->withCount(['documents as visible_documents_count' => function (Builder $documents): void {
                    if (! \App\Support\Fleet\VehicleDocumentAccess::canSensitive(auth()->user())) {
                        $documents->where('is_sensitive', false);
                    }
                }])
                ->withCount(['changes as pending_changes_count' => fn ($changes) => $changes
                    ->where('status', UnitMasterVehicleChange::STATUS_PROPOSED)]))
            ->columns([
                // Secondary values sit under a main column ("stacked"), so the table needs fewer columns.
                // Each stacked value stays its own sortable, searchable and toggleable column, hidden by default.
                Tables\Columns\TextColumn::make('veh_number')
                    ->label('Veh #')
                    ->description(fn (UnitMasterVehicle $record): ?string => filled($record->tag_number) ? 'Tag '.$record->tag_number : null)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tag_number')
                    ->label('Tag #')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('asset_category')
                    ->label('Category')
                    ->badge()
                    ->getStateUsing(fn (UnitMasterVehicle $record): string => $record->categoryLabel())
                    ->color(fn (UnitMasterVehicle $record): string => $record->isNonroad() ? 'info' : 'gray')
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('section')
                    ->description(fn (UnitMasterVehicle $record): ?string => filled($record->dept_code) ? 'Dept. '.$record->dept_code : null)
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('dept_code')
                    ->label('Dept.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('make')
                    ->label('Make / model / year')
                    ->description(fn (UnitMasterVehicle $record): ?string => collect([$record->model, $record->year])->filter()->implode(' · ') ?: null)
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('model')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('year')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('color')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('fleet_status_code')
                    ->label('FY27 status')
                    ->toggleable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('fleet_class_description')
                    ->label('Class description')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('employee_or_vehicle_name')
                    ->label('Employee/Vehicle')
                    // The driver sits under the name; when there is no name the driver takes its place.
                    ->getStateUsing(fn (UnitMasterVehicle $record): ?string => filled($record->employee_or_vehicle_name) ? $record->employee_or_vehicle_name : ($record->assignment ?: null))
                    ->description(fn (UnitMasterVehicle $record): ?string => filled($record->employee_or_vehicle_name) && filled($record->assignment) ? $record->assignment : null)
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('assignment')
                    ->label('Assignment')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('location')
                    ->label('Location')
                    ->searchable()
                    ->wrap()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('latestTakeHomeInspection.submitted_at')
                    ->label('Last take-home inspection')
                    ->dateTime('M j, Y g:i A', 'America/New_York')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pending_changes_count')
                    ->label('Pending corrections')
                    ->badge()
                    ->color('warning')
                    ->getStateUsing(fn (UnitMasterVehicle $record): ?int => $record->pending_changes_count ?: null)
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('visible_documents_count')
                    ->label('Documents')->badge()->color('gray')
                    ->sortable()
                    ->visibleFrom('md'),
                Tables\Columns\IconColumn::make('needs_fleet_review')
                    ->label('Review')
                    ->getStateUsing(fn (UnitMasterVehicle $record): ?string => $record->needs_fleet_review ? 'review' : null)
                    ->icon(fn (?string $state): ?string => $state ? 'heroicon-o-exclamation-triangle' : null)
                    ->color('warning')
                    ->tooltip(fn (UnitMasterVehicle $record): ?string => $record->needs_fleet_review ? 'Needs Fleet review' : null)
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('als_license')
                    ->label('ALS License')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('VIN / Serial #')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sunpass_number')
                    ->label('Sunpass')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('section')
                    ->options(fn () => UnitMasterVehicle::whereNotNull('section')
                        ->distinct()
                        ->pluck('section', 'section')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('dept_code')
                    ->label('Department')
                    ->options(fn () => UnitMasterVehicle::whereNotNull('dept_code')
                        ->distinct()
                        ->pluck('dept_code', 'dept_code')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('asset_category')
                    ->label('Category')
                    ->options(UnitMasterVehicle::CATEGORY_LABELS)
                    ->query(function (Builder $query, array $data): Builder {
                        $category = $data['value'] ?? null;

                        return match (true) {
                            blank($category) => $query,
                            $category === UnitMasterVehicle::CATEGORY_VEHICLE => $query->where(fn (Builder $q) => $q
                                ->whereNull('asset_category')->orWhere('asset_category', $category)),
                            default => $query->where('asset_category', $category),
                        };
                    }),
                Tables\Filters\SelectFilter::make('fleet_status_code')
                    ->label('FY27 status')
                    ->options(fn () => UnitMasterVehicle::whereNotNull('fleet_status_code')
                        ->distinct()
                        ->orderBy('fleet_status_code')
                        ->pluck('fleet_status_code', 'fleet_status_code')
                        ->toArray()),
                Tables\Filters\Filter::make('needs_fleet_review')
                    ->label('Needs Fleet review')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('needs_fleet_review', true)),
                Tables\Filters\Filter::make('has_pending_corrections')
                    ->label('Has pending corrections')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'changes',
                        fn (Builder $changes) => $changes->where('status', UnitMasterVehicleChange::STATUS_PROPOSED),
                    )),
            ])
            ->recordUrl(fn (UnitMasterVehicle $record): string => static::getUrl('view', ['record' => $record]))
            ->actions([
                Tables\Actions\EditAction::make(),
                // The Apparatus review worklist's decisions; each shows only when it applies to the row.
                ApparatusReviewActions::createApparatus(Tables\Actions\Action::make('createApparatusRecord')),
                ApparatusReviewActions::notApparatus(Tables\Actions\Action::make('markNotApparatus')),
                ApparatusReviewActions::undoNotApparatus(Tables\Actions\Action::make('undoNotApparatus')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            // Vehicles with take-home inspection history are kept (the history is immutable).
                            $kept = $records->filter(fn (\Illuminate\Database\Eloquent\Model $vehicle): bool => $vehicle instanceof UnitMasterVehicle && ($vehicle->takeHomeInspections()->exists() || $vehicle->documents()->exists() || $vehicle->apparatus()->exists()));
                            $records->diff($kept)->each->delete();
                            if ($kept->isNotEmpty()) {
                                \Filament\Notifications\Notification::make()
                                    ->title($kept->count().' vehicle(s) kept because they have inspection or document history or are fire apparatus')
                                    ->warning()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->defaultSort('veh_number'));
    }

    public static function getRelations(): array
    {
        return [UnitMasterVehicleResource\RelationManagers\DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnitMasterVehicles::route('/'),
            'create' => Pages\CreateUnitMasterVehicle::route('/create'),
            'import-review' => Pages\ReviewVehicleDocuments::route('/import-review'),
            'view' => Pages\ViewUnitMasterVehicle::route('/{record}'),
            'edit' => Pages\EditUnitMasterVehicle::route('/{record}/edit'),
        ];
    }

    /** "Name (employee ID)" options for the current-driver picker, capped at 25. */
    public static function employeeOptions(string $search): array
    {
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($search))).'%';

        return Employee::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(employee_id) LIKE ?', [$term]))
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => self::employeeLabel($employee)])
            ->all();
    }

    public static function employeeLabel(?Employee $employee): ?string
    {
        return $employee === null ? null : "{$employee->name} ({$employee->employee_id})";
    }
}
