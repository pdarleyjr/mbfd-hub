<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

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

class RoomsRelationManager extends RelationManager
{
    protected static string $relationship = 'rooms';
    protected static ?string $title = 'Station Rooms';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Room Name'),
                Forms\Components\TextInput::make('floor')
                    ->numeric()
                    ->default(1),
                Forms\Components\Select::make('room_type')
                    ->options([
                        'bay' => 'Apparatus Bay',
                        'office' => 'Office',
                        'kitchen' => 'Kitchen',
                        'bunk' => 'Bunk Room',
                        'common' => 'Common Area',
                        'storage' => 'Storage',
                        'workout' => 'Workout Room',
                        'bathroom' => 'Bathroom',
                        'other' => 'Other',
                    ])
                    ->required(),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('room_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'bay' => 'danger',
                        'office' => 'info',
                        'kitchen' => 'warning',
                        'bunk' => 'success',
                        'storage' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('floor')
                    ->sortable(),
                Tables\Columns\TextColumn::make('assets_count')
                    ->counts('assets')
                    ->label('Assets'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('room_type')
                    ->options([
                        'bay' => 'Apparatus Bay',
                        'office' => 'Office',
                        'kitchen' => 'Kitchen',
                        'bunk' => 'Bunk Room',
                        'common' => 'Common Area',
                        'storage' => 'Storage',
                        'workout' => 'Workout Room',
                        'bathroom' => 'Bathroom',
                        'other' => 'Other',
                    ]),
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
                            ->withFilename('mbfd_station_rooms_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_station_rooms_' . date('Y-m-d'))
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
                                ->withFilename('mbfd_station_rooms_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_station_rooms_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}