<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryLocationResource\Pages;
use App\Models\InventoryLocation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

class InventoryLocationResource extends Resource
{
    protected static ?string $model = InventoryLocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Fire Equipment';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'location_name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('location_name')
                    ->required()
                    ->maxLength(255)
                    ->label('Location Name')
                    ->placeholder('e.g., Supply Closet'),
                Forms\Components\Select::make('shelf')
                    ->options([
                        'A' => 'A',
                        'B' => 'B',
                        'C' => 'C',
                        'D' => 'D',
                        'E' => 'E',
                        'F' => 'F',
                    ]),
                Forms\Components\TextInput::make('row')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10),
                Forms\Components\TextInput::make('bin')
                    ->maxLength(255),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_location')
                    ->label('Location')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('location_name', 'like', "%{$search}%");
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('location_name', $direction);
                    }),
                Tables\Columns\TextColumn::make('equipment_items_count')
                    ->label('Items')
                    ->counts('equipmentItems')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('notes')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('shelf')
                    ->options([
                        'A' => 'A',
                        'B' => 'B',
                        'C' => 'C',
                        'D' => 'D',
                        'E' => 'E',
                        'F' => 'F',
                    ]),
                Tables\Filters\SelectFilter::make('row')
                    ->options([
                        '1' => '1',
                        '2' => '2',
                        '3' => '3',
                        '4' => '4',
                        '5' => '5',
                        '6' => '6',
                        '7' => '7',
                        '8' => '8',
                        '9' => '9',
                        '10' => '10',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('view_items')
                    ->label('View Items')
                    ->icon('heroicon-o-eye')
                    ->url(fn (InventoryLocation $record): string => 
                        EquipmentItemResource::getUrl('index', ['tableFilters' => ['location_id' => ['value' => $record->id]]])
                    ),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryLocations::route('/'),
            'create' => Pages\CreateInventoryLocation::route('/create'),
            'edit' => Pages\EditInventoryLocation::route('/{record}/edit'),
        ];
    }
}
