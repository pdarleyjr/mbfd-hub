<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DateTimePicker::make('inspection_date')
                    ->required()
                    ->default(now()),
                
                Forms\Components\TextInput::make('officer_name')
                    ->required()
                    ->maxLength(255),
                
                Forms\Components\TextInput::make('officer_badge')
                    ->maxLength(50),
                
                Forms\Components\Select::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ])
                    ->required(),
                
                Forms\Components\Textarea::make('notes')
                    ->rows(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('inspection_date')
            ->columns([
                Tables\Columns\TextColumn::make('inspection_date')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('officer_name')
                    ->label('Officer')
                    ->searchable(),
                
                Tables\Columns\BadgeColumn::make('shift')
                    ->colors([
                        'primary' => 'A',
                        'warning' => 'B',
                        'success' => 'C',
                    ]),
                
                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('inspection_date', 'desc');
    }
}
