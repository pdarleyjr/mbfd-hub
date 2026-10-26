<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Resources\Under25kProjectResource;
use App\Models\Under25kProject;
use App\Services\Exports\ExportableTable;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class Under25kProjectsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'under25kProjects';

    protected static ?string $title = 'Under $25k Projects';

    public function table(Table $table): Table
    {
        return static::withExportActions($table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Project Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('project_number')
                    ->label('Project #')
                    ->searchable(),
                Tables\Columns\TextColumn::make('budget_amount')
                    ->label('Budget')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Planning' => 'gray',
                        'In Progress' => 'warning',
                        'Completed' => 'success',
                        'On Hold' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('percent_complete')
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
                    ->authorize(fn (): bool => $this->canAssignProjects())
                    ->form([
                        Forms\Components\Select::make('project_id')
                            ->label('Select Project')
                            ->options(fn () => Under25kProject::whereNull('station_id')
                                ->orWhere('station_id', '!=', $this->getOwnerRecord()->id)
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        Under25kProject::find($data['project_id'])->update([
                            'station_id' => $this->getOwnerRecord()->id,
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (Under25kProject $record): string => Under25kProjectResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ])
            ->bulkActions([]));
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
