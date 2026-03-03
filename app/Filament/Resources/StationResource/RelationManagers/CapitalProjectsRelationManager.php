<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Resources\CapitalProjectResource;
use App\Models\CapitalProject;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

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
                    ->form([
                        Forms\Components\Select::make('project_id')
                            ->label('Select Project')
                            ->options(fn () => CapitalProject::whereNull('station_id')
                                ->orWhere('station_id', '!=', $this->getOwnerRecord()->id)
                                ->pluck('project_name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        CapitalProject::find($data['project_id'])->update([
                            'station_id' => $this->getOwnerRecord()->id,
                        ]);
                    }),
            ])
            
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export')
                    ->color('gray')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exports([
                        ExcelExport::make('xlsx')
                            ->label('Export as Excel (.xlsx)')
                            ->fromTable()
                            ->withFilename('mbfd_station_cp_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_station_cp_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (CapitalProject $record): string => CapitalProjectResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ])
            ->bulkActions([                    ExportBulkAction::make('export_selected')
                        ->label('Export Selected')
                        ->exports([
                            ExcelExport::make('xlsx')
                                ->label('Export as Excel (.xlsx)')
                                ->fromTable()
                                ->withFilename('mbfd_station_cp_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_station_cp_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
]);
    }
}
