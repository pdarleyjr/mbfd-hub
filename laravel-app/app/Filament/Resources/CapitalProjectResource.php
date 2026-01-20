<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CapitalProjectResource\Pages;
use App\Filament\Resources\CapitalProjectResource\RelationManagers;
use App\Models\CapitalProject;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CapitalProjectResource extends Resource
{
    protected static ?string $model = CapitalProject::class;

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
                Forms\Components\TextInput::make('budget')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('spend')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('Planning'),
                Forms\Components\DatePicker::make('start_date'),
                Forms\Components\DatePicker::make('estimated_completion'),
                Forms\Components\DatePicker::make('actual_completion'),
                Forms\Components\TextInput::make('project_manager')
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
                Tables\Columns\TextColumn::make('budget')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('spend')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('estimated_completion')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('actual_completion')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('project_manager')
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
            'index' => Pages\ListCapitalProjects::route('/'),
            'create' => Pages\CreateCapitalProject::route('/create'),
            'edit' => Pages\EditCapitalProject::route('/{record}/edit'),
        ];
    }
}
