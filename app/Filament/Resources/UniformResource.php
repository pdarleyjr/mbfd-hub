<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UniformResource\Pages;
use App\Models\Uniform;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UniformResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $model = Uniform::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Inventory & Logistics';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('item_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('size')
                    ->options(array_combine(
                        ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
                        ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
                    )),
                Forms\Components\TextInput::make('quantity_on_hand')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Forms\Components\TextInput::make('reorder_level')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(10),
                Forms\Components\TextInput::make('unit_cost')
                    ->numeric()
                    ->minValue(0),
                Forms\Components\TextInput::make('supplier')
                    ->maxLength(255),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('size')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity_on_hand')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reorder_level')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit_cost')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
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
            'index' => Pages\ListUniforms::route('/'),
            'create' => Pages\CreateUniform::route('/create'),
            'edit' => Pages\EditUniform::route('/{record}/edit'),
        ];
    }
}
