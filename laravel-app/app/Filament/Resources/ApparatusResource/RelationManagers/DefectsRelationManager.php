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
                
                Forms\Components\Select::make('issue_type')
                    ->required()
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ]),
                
                Forms\Components\Textarea::make('notes')
                    ->rows(3),
                
                Forms\Components\Select::make('status')
                    ->required()
                    ->default('open')
                    ->options([
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved' => 'Resolved',
                    ]),
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
                        'danger' => 'open',
                        'warning' => 'in_progress',
                        'success' => 'resolved',
                    ]),
                
                Tables\Columns\BadgeColumn::make('issue_type')
                    ->label('Issue Type')
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state))),
                
                Tables\Columns\TextColumn::make('reported_date')
                    ->label('Reported')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_resolved')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ApparatusDefect $record) => $record->status !== 'resolved')
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Resolution Notes')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (ApparatusDefect $record, array $data) {
                        $record->update([
                            'status' => 'resolved',
                            'resolution_notes' => $data['resolution_notes'],
                            'resolved_at' => now(),
                        ]);
                    }),
                
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('reported_date', 'desc');
    }
}
