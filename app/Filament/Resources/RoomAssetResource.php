<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\RoomAssetResource\Pages;
use App\Models\RoomAsset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoomAssetResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = RoomAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Station Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Room Assets';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Location and identity')->schema([
                Forms\Components\Select::make('room_id')
                    ->relationship('room', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => "Station {$record->station?->station_number} — {$record->name}")
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('asset_tag')->maxLength(255),
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('location_within_room')->maxLength(255),
                Forms\Components\TextInput::make('category')->maxLength(100),
                Forms\Components\TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
                Forms\Components\TextInput::make('unit')->maxLength(50),
                Forms\Components\Select::make('condition')->options([
                    'new' => 'New', 'excellent' => 'Excellent', 'good' => 'Good',
                    'fair' => 'Fair', 'poor' => 'Poor', 'needs_repair' => 'Needs Repair',
                    'out_of_service' => 'Out of Service', 'retired' => 'Retired',
                ]),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(3),
            Forms\Components\Section::make('Asset details')->schema([
                Forms\Components\TextInput::make('manufacturer')->maxLength(255),
                Forms\Components\TextInput::make('model_number')->maxLength(255),
                Forms\Components\TextInput::make('serial_number')->maxLength(255),
                Forms\Components\DatePicker::make('purchase_date'),
                Forms\Components\TextInput::make('purchase_price')->numeric()->minValue(0)->prefix('$'),
                Forms\Components\Textarea::make('description')->columnSpanFull(),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)->columns([
            Tables\Columns\TextColumn::make('room.station.station_number')->label('Station')->sortable(),
            Tables\Columns\TextColumn::make('room.name')->label('Room')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('asset_tag')->label('Asset Tag')->searchable(),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('category')->badge(),
            Tables\Columns\TextColumn::make('quantity')->numeric()->sortable(),
            Tables\Columns\TextColumn::make('condition')->badge(),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
            Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('room_id')->relationship('room', 'name')->searchable()->preload(),
            Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
        ])->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\EditAction::make(),
        ])->defaultSort('updated_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Asset Profile')->schema([
                Infolists\Components\TextEntry::make('room.station.station_number')->label('Station'),
                Infolists\Components\TextEntry::make('room.name')->label('Room'),
                Infolists\Components\TextEntry::make('asset_tag')->label('Asset Tag')->placeholder('—'),
                Infolists\Components\TextEntry::make('name'),
                Infolists\Components\TextEntry::make('category')->badge()->placeholder('—'),
                Infolists\Components\TextEntry::make('condition')->badge()->placeholder('—'),
                Infolists\Components\TextEntry::make('quantity'),
                Infolists\Components\IconEntry::make('is_active')->boolean(),
                Infolists\Components\TextEntry::make('manufacturer')->placeholder('—'),
                Infolists\Components\TextEntry::make('model_number')->placeholder('—'),
                Infolists\Components\TextEntry::make('serial_number')->placeholder('—'),
                Infolists\Components\TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                Infolists\Components\TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
            ])->columns(4),
            Infolists\Components\Section::make('Lifecycle Events')->schema([
                Infolists\Components\RepeatableEntry::make('events')->hiddenLabel()->schema([
                    Infolists\Components\TextEntry::make('event_at')->dateTime(),
                    Infolists\Components\TextEntry::make('event_type')->badge(),
                    Infolists\Components\TextEntry::make('stationRequest.request_number')->label('Request')->placeholder('—'),
                    Infolists\Components\TextEntry::make('changedBy.name')->label('Changed By')->placeholder('System'),
                    Infolists\Components\TextEntry::make('vendor')->placeholder('—'),
                    Infolists\Components\TextEntry::make('cost')->money('USD')->placeholder('—'),
                    Infolists\Components\TextEntry::make('notes')->columnSpan(2)->placeholder('—'),
                ])->columns(4),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['room.station', 'events.stationRequest', 'events.changedBy']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoomAssets::route('/'),
            'create' => Pages\CreateRoomAsset::route('/create'),
            'view' => Pages\ViewRoomAsset::route('/{record}'),
            'edit' => Pages\EditRoomAsset::route('/{record}/edit'),
        ];
    }
}
