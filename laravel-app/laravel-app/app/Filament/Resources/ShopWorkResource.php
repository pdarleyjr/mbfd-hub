<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopWorkResource\Pages;
use App\Filament\Resources\ShopWorkResource\RelationManagers;
use App\Models\ShopWork;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ShopWorkResource extends Resource
{
    protected static ?string $model = ShopWork::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('project_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('apparatus_id')
                    ->numeric(),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('Pending'),
                Forms\Components\Textarea::make('parts_list')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('estimated_cost')
                    ->numeric(),
                Forms\Components\TextInput::make('actual_cost')
                    ->numeric(),
                Forms\Components\DatePicker::make('started_date'),
                Forms\Components\DatePicker::make('completed_date'),
                Forms\Components\TextInput::make('assigned_to')
                    ->maxLength(255),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('apparatus_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('estimated_cost')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('actual_cost')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('started_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('assigned_to')
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
            'index' => Pages\ListShopWorks::route('/'),
            'create' => Pages\CreateShopWork::route('/create'),
            'edit' => Pages\EditShopWork::route('/{record}/edit'),
        ];
    }
}
