<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Inspections';

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
                    ->dateTime()
                    ->sortable(),
                
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
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
                            ->withFilename('mbfd_apparatus_inspections_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_apparatus_inspections_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                                        ExportBulkAction::make('export_selected')
                        ->label('Export Selected')
                        ->exports([
                            ExcelExport::make('xlsx')
                                ->label('Export as Excel (.xlsx)')
                                ->fromTable()
                                ->withFilename('mbfd_apparatus_inspections_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_apparatus_inspections_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('completed_at', 'desc');
    }
}
