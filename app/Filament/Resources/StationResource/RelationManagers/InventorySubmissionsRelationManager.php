<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Resources\StationInventorySubmissionResource;
use App\Models\StationInventorySubmission;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class InventorySubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventorySubmissions';
    protected static ?string $title = 'Inventory Submissions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        'C' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->timezone('America/New_York'),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            ->headerActions([
                //
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
                            ->withFilename('mbfd_inventory_submissions_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_inventory_submissions_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (StationInventorySubmission $record): string => 
                        StationInventorySubmissionResource::getUrl('view', ['record' => $record])
                    )
                    ->openUrlInNewTab(false),
            ])
            ->bulkActions([                    ExportBulkAction::make('export_selected')
                        ->label('Export Selected')
                        ->exports([
                            ExcelExport::make('xlsx')
                                ->label('Export as Excel (.xlsx)')
                                ->fromTable()
                                ->withFilename('mbfd_inventory_submissions_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_inventory_submissions_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
]);
    }
}
