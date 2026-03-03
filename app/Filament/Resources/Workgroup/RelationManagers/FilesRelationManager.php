<?php

namespace App\Filament\Resources\Workgroup\RelationManagers;

use App\Models\WorkgroupSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Files';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('filepath')
                    ->label('File')
                    ->directory('workgroup-files')
                    ->visibility('private')
                    ->required(),
                Forms\Components\Select::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\TextInput::make('file_type')
                    ->label('File Type')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('filename')
            ->columns([
                Tables\Columns\TextColumn::make('filename')
                    ->label('Filename')
                    ->searchable(),
                Tables\Columns\TextColumn::make('session.name')
                    ->label('Session')
                    ->searchable(),
                Tables\Columns\TextColumn::make('file_type')
                    ->label('Type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('Uploaded By')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Uploaded'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id')),
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
                            ->withFilename('mbfd_wg_rm_files_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_wg_rm_files_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => route('workgroup.file.download', ['file' => $record->id]))
                    ->openUrlInNewTab(),
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
                                ->withFilename('mbfd_wg_rm_files_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_wg_rm_files_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
