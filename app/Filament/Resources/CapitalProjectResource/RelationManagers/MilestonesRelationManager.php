<?php

namespace App\Filament\Resources\CapitalProjectResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\DatePicker::make('due_date')
                    ->required()
                    ->native(false)
                    ->displayFormat('M d, Y'),
                Forms\Components\Toggle::make('completed')
                    ->default(false)
                    ->live(),
                Forms\Components\DatePicker::make('completed_at')
                    ->native(false)
                    ->displayFormat('M d, Y')
                    ->visible(fn (Forms\Get $get) => $get('completed') === true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->date('M d, Y')
                    ->sortable(),
                Tables\Columns\IconColumn::make('completed')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->date('M d, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('completed')
                    ->query(fn (Builder $query): Builder => $query->where('completed', true))
                    ->label('Completed'),
                Tables\Filters\Filter::make('pending')
                    ->query(fn (Builder $query): Builder => $query->where('completed', false))
                    ->label('Pending'),
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
                            ->withFilename('mbfd_milestones_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_milestones_' . date('Y-m-d'))
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
                                ->withFilename('mbfd_milestones_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_milestones_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('due_date', 'asc');
    }
}
