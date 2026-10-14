<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Resources\CapitalProjectResource;
use App\Models\CapitalProject;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CapitalProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'capitalProjects';

    protected static ?string $title = 'Capital Projects';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Project Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('project_number')
                    ->label('Project #')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('budget_amount')
                    ->label('Budget')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('percent_complete')
                    ->label('Progress')
                    ->suffix('%')
                    ->default(0),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Planning',
                        'in_progress' => 'In Progress',
                        'on_hold' => 'On Hold',
                        'completed' => 'Completed',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('assignProject')
                    ->label('Assign Project')
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => $this->canAssignProjects())
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
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (CapitalProject $record): string => CapitalProjectResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ])
            ->bulkActions([]);
    }

    /**
     * Assigning a project changes the project record and the station profile,
     * so both the station and project management capabilities are required.
     */
    private function canAssignProjects(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can('admin.stations.manage')
            && $user->can('admin.projects.manage');
    }
}
