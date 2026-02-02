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
    protected static ?string $title = 'Assigned Apparatus';

    protected function getTableQuery(): ?Builder
    {
        // Filter apparatuses where current_location matches "Station {station_number}"
        $stationNumber = $this->getOwnerRecord()->station_number;
        return Apparatus::query()->where('current_location', 'Station ' . $stationNumber);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('unit_number')
            ->columns([
                Tables\Columns\TextColumn::make('unit_number')
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
                            ->options(fn () => Apparatus::whereNot('current_location', 'Station ' . $this->getOwnerRecord()->station_number)
                                ->pluck('unit_number', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        Apparatus::find($data['apparatus_id'])->update([
                            'current_location' => 'Station ' . $this->getOwnerRecord()->station_number,
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('reassign')
                    ->label('Reassign')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Forms\Components\Select::make('new_location')
                            ->label('New Location')
                            ->options([
                                'Fire Fleet' => 'Fire Fleet',
                                'Station 1' => 'Station 1',
                                'Station 2' => 'Station 2',
                                'Station 3' => 'Station 3',
                                'Station 4' => 'Station 4',
                            ])
                            ->required(),
                    ])
                    ->action(function (Apparatus $record, array $data) {
                        $record->update(['current_location' => $data['new_location']]);
                    }),
            ])
            ->bulkActions([]);
    }
}