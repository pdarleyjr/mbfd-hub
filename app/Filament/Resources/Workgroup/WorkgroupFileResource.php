<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Pages;
use App\Models\Workgroup;
use App\Models\WorkgroupFile;
use App\Models\WorkgroupSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class WorkgroupFileResource extends Resource
{
    protected static ?string $model = WorkgroupFile::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('File Information')
                    ->schema([
                        Forms\Components\FileUpload::make('filepath')
                            ->label('File')
                            ->directory('workgroup-files')
                            ->visibility('private')
                            ->required()
                            ->storeFileNamesIn('filename')
                            ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/*'])
                            ->maxSize(51200),
                    ]),
                Forms\Components\Section::make('Association')
                    ->schema([
                        Forms\Components\Select::make('workgroup_id')
                            ->label('Workgroup')
                            ->options(fn () => Workgroup::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn ($state, callable $set) => $set('workgroup_session_id', null)),
                        Forms\Components\Select::make('workgroup_session_id')
                            ->label('Session (Optional)')
                            ->options(function (callable $get) {
                                $workgroupId = $get('workgroup_id');
                                if (!$workgroupId) {
                                    return WorkgroupSession::pluck('name', 'id');
                                }
                                return WorkgroupSession::where('workgroup_id', $workgroupId)->pluck('name', 'id');
                            })
                            ->searchable(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('filename')
                    ->searchable()
                    ->sortable()
                    ->label('Filename'),
                Tables\Columns\TextColumn::make('workgroup.name')
                    ->searchable()
                    ->sortable()
                    ->label('Workgroup'),
                Tables\Columns\TextColumn::make('session.name')
                    ->searchable()
                    ->label('Session'),
                Tables\Columns\TextColumn::make('file_type')
                    ->searchable()
                    ->label('Type'),
                Tables\Columns\TextColumn::make('uploader.name')
                    ->searchable()
                    ->label('Uploaded By'),
                Tables\Columns\TextColumn::make('file_size')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024, 2) . ' KB' : '-')
                    ->label('Size'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_id')
                    ->label('Workgroup')
                    ->options(fn () => Workgroup::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('file_type')
                    ->label('File Type')
                    ->options([
                        'pdf' => 'PDF',
                        'doc' => 'Word',
                        'docx' => 'Word',
                        'xls' => 'Excel',
                        'xlsx' => 'Excel',
                        'jpg' => 'Image',
                        'jpeg' => 'Image',
                        'png' => 'Image',
                    ]),
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
                            ->withFilename('mbfd_wg_files_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_wg_files_' . date('Y-m-d'))
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
                                ->withFilename('mbfd_wg_files_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_wg_files_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkgroupFiles::route('/'),
            'create' => Pages\CreateWorkgroupFile::route('/create'),
            'edit' => Pages\EditWorkgroupFile::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public static function canEdit($record): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }
}
