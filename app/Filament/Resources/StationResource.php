<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StationResource\Pages;
use App\Filament\Resources\StationResource\RelationManagers;
use App\Models\Station;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StationResource extends Resource
{
    protected static ?string $model = Station::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Station Information')
                    ->schema([
                        Forms\Components\TextInput::make('station_number')
                            ->required()
                            ->maxLength(255)
                            ->label('Station Number'),
                        Forms\Components\TextInput::make('captain_in_charge')
                            ->maxLength(255)
                            ->label('Captain in Charge'),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                    ])->columns(3),
                Forms\Components\Section::make('Address')
                    ->schema([
                        Forms\Components\TextInput::make('address')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
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
                Infolists\Components\Section::make('Station Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('station_number')
                            ->label('Station Number'),
                        Infolists\Components\TextEntry::make('captain_in_charge')
                            ->label('Captain in Charge'),
                        Infolists\Components\TextEntry::make('phone'),
                    ])->columns(3),
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
                            ->label('Apparatus Count')
                            ->state(fn ($record) => $record->apparatuses()->count()),
                        Infolists\Components\TextEntry::make('rooms_count')
                            ->label('Rooms Count')
                            ->state(fn ($record) => $record->rooms()->count()),
                    ])->columns(2),
                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->columnSpanFull(),
                    ])->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('station_number')
                    ->searchable()
                    ->sortable()
                    ->label('Station'),
                Tables\Columns\TextColumn::make('address')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('captain_in_charge')
                    ->searchable()
                    ->label('Captain'),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('apparatuses_count')
                    ->counts('apparatuses')
                    ->label('Apparatus'),
                Tables\Columns\TextColumn::make('rooms_count')
                    ->counts('rooms')
                    ->label('Rooms'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RoomsRelationManager::class,
            RelationManagers\ApparatusesRelationManager::class,
            RelationManagers\CapitalProjectsRelationManager::class,
            RelationManagers\Under25kProjectsRelationManager::class,
            RelationManagers\InventorySubmissionsRelationManager::class,
            RelationManagers\SingleGasMetersRelationManager::class,
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
