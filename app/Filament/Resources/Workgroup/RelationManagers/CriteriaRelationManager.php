<?php

namespace App\Filament\Resources\Workgroup\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class CriteriaRelationManager extends RelationManager
{
    protected static string $relationship = 'criteria';

    protected static ?string $title = 'Criteria';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Criterion Name'),
                Forms\Components\Textarea::make('description')
                    ->maxLength(500)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('max_score')
                    ->numeric()
                    ->label('Max Score')
                    ->default(10)
                    ->minValue(1),
                Forms\Components\TextInput::make('weight')
                    ->numeric()
                    ->label('Weight')
                    ->default(1.0)
                    ->step(0.1)
                    ->minValue(0.1),
                Forms\Components\TextInput::make('display_order')
                    ->numeric()
                    ->label('Display Order')
                    ->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('display_order')
                    ->sortable()
                    ->label('Order'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('max_score')
                    ->sortable()
                    ->label('Max Score'),
                Tables\Columns\TextColumn::make('weight')
                    ->sortable()
                    ->label('Weight'),
            ])
            ->defaultSort('display_order')
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
                            ->withFilename('mbfd_wg_criteria_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_wg_criteria_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
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
                                ->withFilename('mbfd_wg_criteria_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_wg_criteria_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
