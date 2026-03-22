<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\ApparatusInspection;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Vehicle Inspections';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('operator_name')
                    ->label('Operator Name')
                    ->required()
                    ->maxLength(255),
                
                Forms\Components\TextInput::make('rank')
                    ->maxLength(50),
                
                Forms\Components\Select::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ])
                    ->required(),
                
                Forms\Components\TextInput::make('unit_number')
                    ->maxLength(50),
                
                Forms\Components\DateTimePicker::make('completed_at')
                    ->label('Completed At')
                    ->default(now()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('completed_at')
            ->columns([
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('current_designation')
                    ->label('Designation')
                    ->getStateUsing(fn (ApparatusInspection $record) => $record->apparatus?->designation ?? $record->designation_at_time ?? '—')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Vehicle #')
                    ->getStateUsing(fn (ApparatusInspection $record) => $record->vehicle_number ?? $record->apparatus?->vehicle_number ?? '—'),

                Tables\Columns\TextColumn::make('operator_name')
                    ->label('Operator')
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('rank')
                    ->label('Rank'),
                
                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'primary',
                        'B' => 'warning',
                        'C' => 'success',
                        default => 'gray',
                    }),
                
                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('view_results')
                    ->label('View Results')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (ApparatusInspection $record): string => route('filament.admin.resources.apparatuses.view-inspection', [
                        'record' => $record->apparatus_id,
                        'inspection' => $record->id,
                    ]))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('completed_at', 'desc');
    }
}
