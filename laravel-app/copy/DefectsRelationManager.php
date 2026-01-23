<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\ApparatusDefect;

class DefectsRelationManager extends RelationManager
{
    protected static string $relationship = 'defects';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('compartment')
                    ->required()
                    ->maxLength(255),
                
                Forms\Components\TextInput::make('item')
                    ->required()
                    ->maxLength(255),
                
                Forms\Components\Select::make('status')
                    ->required()
                    ->options([
                        'Present' => 'Present',
                        'Missing' => 'Missing',
                        'Damaged' => 'Damaged',
                    ]),
                
                Forms\Components\Textarea::make('notes')
                    ->rows(3),
                
                Forms\Components\Toggle::make('resolved')
                    ->label('Resolved')
                    ->default(false),
                
                Forms\Components\Textarea::make('resolution_notes')
                    ->rows(3)
                    ->visible(fn ($get) => $get('resolved')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item')
            ->columns([
                Tables\Columns\TextColumn::make('compartment')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('item')
                    ->searchable(),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'Present',
                        'danger' => 'Missing',
                        'warning' => 'Damaged',
                    ]),
                
                Tables\Columns\IconColumn::make('resolved')
                    ->boolean()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Reported')
                    ->dateTime()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('resolved_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('resolved')
                    ->label('Resolved')
                    ->placeholder('All')
                    ->trueLabel('Resolved')
                    ->falseLabel('Open'),
            ])
            ->headerActions([
                // Defects are created via /daily inspection page
            ])
            ->actions([
                Tables\Actions\Action::make('mark_resolved')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ApparatusDefect $record) => !$record->resolved)
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Resolution Notes')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (ApparatusDefect $record, array $data) {
                        $record->update([
                            'resolved' => true,
                            'resolution_notes' => $data['resolution_notes'],
                            'resolved_at' => now(),
                        ]);
                    }),
                
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No Defects')
            ->emptyStateDescription('Defects are reported from the Daily Checkout page (/daily)')
            ->emptyStateIcon('heroicon-o-shield-check');
    }
}
