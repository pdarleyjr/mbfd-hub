<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\StationResource\Pages;
use App\Filament\Resources\StationResource\RelationManagers;
use App\Filament\Resources\StationResource\StationOperationsSection;
use App\Models\Station;
use App\Services\StationStaffingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StationResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = Station::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Station && parent::canDelete($record) && ! $record->stationInspections()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Station Information')
                    ->schema([
                        Forms\Components\TextInput::make('station_number')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->label('Station Number'),
                        Forms\Components\TextInput::make('name')
                            ->label('Display Name')
                            ->maxLength(255)
                            ->helperText('Shown to members. Leave blank to use "Station" and the station number.')
                            ->afterStateHydrated(fn (Forms\Components\TextInput $component, ?Station $record) => $component->state($record?->getRawOriginal('name')))
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null),
                        Forms\Components\TextInput::make('captain_in_charge')
                            ->maxLength(255)
                            ->label('Captain in Charge'),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active station')
                            ->helperText('Inactive stations keep their history in Admin but are hidden from member station lists.')
                            ->default(true)
                            ->inline(false),
                    ])->columns(3),
                Forms\Components\Section::make('Address')
                    ->id('station-address-section')
                    ->schema([
                        Forms\Components\TextInput::make('address')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['default' => 1, 'lg' => 2]),
                        Forms\Components\TextInput::make('city')
                            ->required()
                            ->maxLength(255)
                            ->default('Miami Beach'),
                        Forms\Components\TextInput::make('state')
                            ->required()
                            ->maxLength(255)
                            ->default('FL'),
                        Forms\Components\TextInput::make('zip_code')
                            ->required()
                            ->maxLength(255),
                    ])->columns(3),
                Forms\Components\Section::make('Station Photo')
                    ->description('Shown on the member station list and station profile. Without a photo, members see the standard station artwork.')
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Photo')
                            ->disk(Station::IMAGE_DISK)
                            ->directory(Station::IMAGE_DIRECTORY)
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(Station::IMAGE_MIME_TYPES)
                            ->maxSize(Station::IMAGE_MAX_KILOBYTES)
                            ->helperText('JPEG, PNG, or WebP, up to 5 MB. A landscape photo of the station front works best.'),
                    ])
                    ->collapsible(),
                Forms\Components\Section::make('Additional Information')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                StationOperationsSection::make(),
                Infolists\Components\Section::make('Station Photo')
                    ->schema([
                        Infolists\Components\ImageEntry::make('image_path')
                            ->hiddenLabel()
                            ->disk(Station::IMAGE_DISK)
                            ->visibility('public')
                            ->height(180)
                            ->placeholder('No photo uploaded. Members see the standard station artwork.'),
                    ])
                    ->collapsible(),
                Infolists\Components\Section::make('Inventory Readiness')
                    ->description('Low stock, ordered items, and supply requests that need attention.')
                    ->schema([
                        Infolists\Components\TextEntry::make('low_stock_count')
                            ->label('Low Stock')
                            ->state(fn (Station $record): int => (int) ($record->getAttribute('low_stock_count') ?? $record->inventoryItems()->where('status', 'low')->count()))
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                        Infolists\Components\TextEntry::make('ordered_stock_count')
                            ->label('Ordered')
                            ->state(fn (Station $record): int => (int) ($record->getAttribute('ordered_stock_count') ?? $record->inventoryItems()->where('status', 'ordered')->count()))
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                        Infolists\Components\TextEntry::make('supply_request_count')
                            ->label('Supply Requests')
                            ->state(fn ($record): int => (int) ($record->supply_request_count ?? $record->supplyRequests()->active()->whereIn('status', ['open', 'ordered'])->count()))
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                        Infolists\Components\TextEntry::make('inventory_link')
                            ->label('Inventory Tab')
                            ->state('Open on-hand inventory')
                            ->url(fn ($record) => static::getUrl('view', ['record' => $record]).'?activeRelationManager=inventoryItems')
                            ->color('primary'),
                    ])
                    ->columns(4),
                Infolists\Components\Section::make('Station Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('station_number')
                            ->label('Station Number'),
                        Infolists\Components\TextEntry::make('name')
                            ->label('Display Name'),
                        Infolists\Components\TextEntry::make('captain_in_charge')
                            ->label('Captain in Charge'),
                        Infolists\Components\TextEntry::make('phone'),
                        Infolists\Components\TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn ($state): string => $state ? 'success' : 'gray'),
                    ])->columns(5),
                Infolists\Components\Section::make('Address')
                    ->schema([
                        Infolists\Components\TextEntry::make('address'),
                        Infolists\Components\TextEntry::make('city'),
                        Infolists\Components\TextEntry::make('state'),
                        Infolists\Components\TextEntry::make('zip_code'),
                    ])->columns(4),
                Infolists\Components\Section::make('Statistics')
                    ->schema([
                        Infolists\Components\TextEntry::make('apparatuses_count')
                            ->label('Assigned Apparatus')
                            ->state(fn (Station $record) => static::staffingValue($record, 'assigned_apparatus_count')),
                        Infolists\Components\TextEntry::make('assigned_personnel_count')
                            ->label('Assigned Personnel')
                            ->state(fn (Station $record) => static::staffingValue($record, 'assigned_personnel_count')),
                        Infolists\Components\TextEntry::make('dorm_beds_count')
                            ->label('Dorm Beds')
                            ->state(fn (Station $record) => static::staffingValue($record, 'dorm_beds_count')),
                        Infolists\Components\TextEntry::make('rooms_count')
                            ->label('Rooms Count')
                            ->state(fn (Station $record): int => (int) ($record->rooms_count ?? $record->rooms()->count())),
                    ])->columns(4),
                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->columnSpanFull(),
                    ])->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            // The three staffing columns share one memoized summary per station,
            // built from apparatus loaded once for the whole page.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'apparatuses' => fn ($apparatus) => $apparatus->select(StationStaffingService::APPARATUS_COLUMNS),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('station_number')
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(fn (Station $record): string => $record->address.' · '.($record->phone ?: 'Phone unrecorded'))
                    ->searchable()
                    ->sortable()
                    ->label('Station'),
                Tables\Columns\TextColumn::make('address')->visibleFrom('md')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('captain_in_charge')->visibleFrom('md')
                    ->searchable()
                    ->label('Captain'),
                Tables\Columns\TextColumn::make('phone')->visibleFrom('md')
                    ->searchable(),
                Tables\Columns\TextColumn::make('apparatuses_count')->visibleFrom('md')
                    ->label('Assigned Apparatus')
                    ->state(fn (Station $record) => static::staffingValue($record, 'assigned_apparatus_count')),
                Tables\Columns\TextColumn::make('assigned_personnel')->visibleFrom('md')
                    ->label('Personnel')
                    ->state(fn (Station $record) => static::staffingValue($record, 'assigned_personnel_count')),
                Tables\Columns\TextColumn::make('dorm_beds')->visibleFrom('md')
                    ->label('Beds')
                    ->state(fn (Station $record) => static::staffingValue($record, 'dorm_beds_count')),
                Tables\Columns\TextColumn::make('rooms_count')->visibleFrom('md')
                    ->counts('rooms')
                    ->label('Rooms'),
                Tables\Columns\IconColumn::make('is_active')->visibleFrom('md')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->visibleFrom('md')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ])->label('Actions')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            ])
            // No bulk delete: stations with inspection evidence must be deactivated,
            // and the single-record delete action already enforces canDelete().
            ->bulkActions([]);
    }

    /**
     * One staffing figure for a station, or "Unknown" when the station has no
     * authoritative complement. The summary is memoized per loaded apparatus set.
     */
    public static function staffingValue(Station $record, string $key): int|string
    {
        $value = app(StationStaffingService::class)->summaryFor($record)[$key] ?? null;

        return is_int($value) ? $value : 'Unknown';
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RoomsRelationManager::class,
            RelationManagers\ApparatusesRelationManager::class,
            RelationManagers\CapitalProjectsRelationManager::class,
            RelationManagers\Under25kProjectsRelationManager::class,

            RelationManagers\SingleGasMetersRelationManager::class,
            RelationManagers\StationInventoryItemsRelationManager::class,
            RelationManagers\StationSupplyRequestsRelationManager::class,
            RelationManagers\InventorySubmissionsRelationManager::class,
            RelationManagers\StationRequestsRelationManager::class,
            RelationManagers\ApparatusServiceTicketsRelationManager::class,
            RelationManagers\DailyCheckoutsRelationManager::class,
            RelationManagers\EquipmentIssuesRelationManager::class,
            RelationManagers\StationInspectionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStations::route('/'),
            'create' => Pages\CreateStation::route('/create'),
            'view' => Pages\ViewStation::route('/{record}'),
            'edit' => Pages\EditStation::route('/{record}/edit'),
        ];
    }
}
