<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Concerns\ResolvesWorkgroupAccess;
use App\Models\Workgroup;
use App\Models\WorkgroupFile;
use App\Models\WorkgroupSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Mime\MimeTypes;

class WorkgroupFileResource extends Resource
{
    use ResolvesWorkgroupAccess;

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
                        self::fileUpload(),
                    ]),
                Forms\Components\Section::make('Association')
                    ->schema([
                        Forms\Components\Select::make('workgroup_id')
                            ->label('Workgroup')
                            ->options(fn () => self::workgroupAccess()->scopeManageWorkgroups(Workgroup::query(), self::currentWorkgroupUser())->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn ($state, callable $set) => $set('workgroup_session_id', null)),
                        Forms\Components\Select::make('workgroup_session_id')
                            ->label('Session (Optional)')
                            ->options(function (callable $get) {
                                $workgroupId = $get('workgroup_id');
                                if (! $workgroupId) {
                                    return [];
                                }

                                return self::workgroupAccess()
                                    ->scopeManageSessions(WorkgroupSession::query(), self::currentWorkgroupUser())
                                    ->where('workgroup_id', $workgroupId)
                                    ->pluck('name', 'id');
                            })
                            ->searchable(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function fileUpload(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('filepath')
            ->label('File')
            ->disk(fn (): string => (string) config('filesystems.private', 'local'))
            ->directory('workgroup-files')
            ->visibility('private')
            ->required()
            ->storeFileNamesIn('filename')
            ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/*'])
            ->maxSize(51200)
            ->helperText('PDF, Office documents, and images up to 50 MB.')
            // Existing files may still be on a legacy disk. Keep their saved path
            // and use the authorized download route instead of a public asset URL.
            ->fetchFileInformation(false)
            ->getUploadedFileUsing(static function (string $file, ?WorkgroupFile $record): ?array {
                if (! $record || $record->filepath !== $file) {
                    return null;
                }

                return [
                    'name' => $record->filename,
                    'size' => $record->file_size ?? 0,
                    'type' => MimeTypes::getDefault()->getMimeTypes($record->file_type ?? '')[0] ?? 'application/octet-stream',
                    'url' => route('workgroup.file.download', $record),
                ];
            });
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
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024, 2).' KB' : '-')
                    ->label('Size'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_id')
                    ->label('Workgroup')
                    ->options(fn () => self::workgroupAccess()->scopeWorkgroups(Workgroup::query(), self::currentWorkgroupUser())->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => self::workgroupAccess()->scopeSessions(WorkgroupSession::query(), self::currentWorkgroupUser())->pluck('name', 'id')),
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
        $user = self::currentWorkgroupUser();

        return $user !== null && self::workgroupAccess()->canEnterPanel($user);
    }

    public static function canCreate(): bool
    {
        $user = self::currentWorkgroupUser();

        return $user !== null && self::workgroupAccess()->canManageAnyWorkgroup($user);
    }

    public static function canEdit($record): bool
    {
        $user = self::currentWorkgroupUser();
        $workgroup = $record instanceof WorkgroupFile
            ? $record->getAttribute('workgroup')
            : null;

        return $user !== null
            && $record instanceof WorkgroupFile
            && $workgroup instanceof Workgroup
            && self::workgroupAccess()->canManageWorkgroup($user, $workgroup);
    }

    public static function canDelete($record): bool
    {
        return self::canEdit($record);
    }

    public static function canView($record): bool
    {
        $user = self::currentWorkgroupUser();

        return $user !== null
            && $record instanceof WorkgroupFile
            && self::workgroupAccess()->canViewFile($user, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::workgroupAccess()->scopeWorkgroupRecords(parent::getEloquentQuery(), self::currentWorkgroupUser());
    }
}
