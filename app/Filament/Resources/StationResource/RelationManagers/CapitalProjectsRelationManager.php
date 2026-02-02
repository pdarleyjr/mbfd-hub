<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Models\CapitalProject;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CapitalProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'capitalProjects';
    protected static ?string $title = 'Capital Projects';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('project_name')
            ->columns([
                Tables\Columns\TextColumn::make('project_name')
                    ->label('Project Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('project_number')
                    ->label('Project #')
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_budget')
                    ->label('Budget')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Planning' => 'gray',
                        'In Progress' => 'warning',
                        'Completed' => 'success',
                        'On Hold' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('completion_percentage')
                    ->label('Progress')
                    ->suffix('%'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'Planning' => 'Planning',
                        'In Progress' => 'In Progress',
                        'Completed' => 'Completed',
                        'On Hold' => 'On Hold',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('assignProject')
                    ->label('Assign Project')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Select::make('project_id')
                            ->label('Select Project')
                            ->options(fn () => CapitalProject::whereNull('station_id')
                                ->orWhere('station_id', '!=', $this->getOwnerRecord()->id)
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        CapitalProject::find($data['project_id'])->update([
                            'station_id' => $this->getOwnerRecord()->id,
                        ]);
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
