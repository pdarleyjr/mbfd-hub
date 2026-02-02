<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Models\Apparatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApparatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'apparatuses';
    protected static ?string $title = 'Assigned Apparatus';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('unit_number')
            ->columns([
                Tables\Columns\TextColumn::make('designation')
                    ->label('Designation')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Vehicle #')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'engine' => 'danger',
                        'ladder' => 'warning',
                        'rescue' => 'info',
                        'battalion' => 'success',
                        'boat' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('make'),
                Tables\Columns\TextColumn::make('model'),
                Tables\Columns\TextColumn::make('year'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'in_service' => 'success',
                        'out_of_service' => 'danger',
                        'maintenance' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'engine' => 'Engine',
                        'ladder' => 'Ladder',
                        'rescue' => 'Rescue',
                        'battalion' => 'Battalion',
                        'boat' => 'Boat',
                        'utility' => 'Utility',
                    ]),
            ])
            ->headerActions([
                // Actions to reassign apparatus to this station
                Tables\Actions\Action::make('assignApparatus')
                    ->label('Assign Apparatus')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Select::make('apparatus_id')
                            ->label('Select Apparatus')
                            ->options(fn () => Apparatus::whereNull('station_id')
                                ->orWhere('station_id', '!=', $this->getOwnerRecord()->id)
                                ->get()
                                ->mapWithKeys(fn ($app) => [$app->id => "{$app->designation} - {$app->vehicle_number}"]))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        Apparatus::find($data['apparatus_id'])->update([
                            'station_id' => $this->getOwnerRecord()->id,
                            'current_location' => 'Station ' . $this->getOwnerRecord()->station_number,
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('reassign')
                    ->label('Reassign')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Forms\Components\Select::make('new_station_id')
                            ->label('New Station')
                            ->options(fn () => \App\Models\Station::pluck('station_number', 'id')
                                ->mapWithKeys(fn ($num, $id) => [$id => "Station {$num}"]))
                            ->required(),
                    ])
                    ->action(function (Apparatus $record, array $data) {
                        $station = \App\Models\Station::find($data['new_station_id']);
                        $record->update([
                            'station_id' => $data['new_station_id'],
                            'current_location' => 'Station ' . $station->station_number,
                        ]);
                    }),
            ])
            ->bulkActions([]);
    }
}