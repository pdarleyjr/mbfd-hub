<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\OperationalFormRecordResource\Pages;
use App\Models\OperationalFormRecord;
use App\Services\OperationalEvidenceArchiveService;
use App\Services\OperationalForms\OperationalFormDeletionService;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class OperationalFormRecordResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = OperationalFormRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Forms';

    protected static ?string $modelLabel = 'Operational Form';

    protected static ?string $pluralModelLabel = 'Operational Forms';

    protected static ?string $slug = 'operational-forms';

    protected static ?int $navigationSort = 100;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'latestDocument']))
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')->label('Employee')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('employee.employee_id')->label('Employee ID')->searchable(),
                Tables\Columns\TextColumn::make('form_type')->label('Form')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    'ics_214' => 'ICS 214',
                    'uploaded_file' => 'Submitted file',
                    default => 'F-ROC Daily Activity Report',
                })->color(fn (string $state) => $state === 'uploaded_file' ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(44),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'completed' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('last_autosaved_at')->label('Last saved')->since()->dateTimeTooltip()->sortable(),
                Tables\Columns\TextColumn::make('latest_pdf_version')->label('Document')->formatStateUsing(
                    fn ($state, OperationalFormRecord $record) => $state
                        ? ($record->form_type === 'uploaded_file' ? 'Submitted file' : "PDF version {$state}")
                        : '—',
                ),
                Tables\Columns\TextColumn::make('latestDocument.created_at')->label('Submitted / generated')->since()->dateTimeTooltip()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('archive_state')->label('Visibility')->options([
                    'active' => 'Active',
                    'archived' => 'Archived',
                    'trash' => 'Trash',
                    'all' => 'All',
                ])->default('active')->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? 'active') {
                        'all' => $query,
                        'trash' => $query->whereNotNull('deleted_at'),
                        'archived' => $query->whereNull('deleted_at')->whereNotNull('archived_at'),
                        default => $query->whereNull('deleted_at')->whereNull('archived_at'),
                    };
                }),
                SelectFilter::make('form_type')->label('Form type')->options([
                    'ics_214' => 'ICS 214',
                    'froc_log_001_ff' => 'F-ROC Daily Activity Report',
                    'uploaded_file' => 'Submitted file',
                ]),
                SelectFilter::make('status')->options(['draft' => 'Draft', 'completed' => 'Completed']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->form([\Filament\Forms\Components\Textarea::make('archive_reason')->label('Reason')->required()->maxLength(2000)])
                    ->visible(fn (OperationalFormRecord $record): bool => self::canDelete($record) && ! $record->isArchived() && ! $record->trashed())
                    ->action(fn (OperationalFormRecord $record, array $data) => app(OperationalEvidenceArchiveService::class)->archive($record, auth()->user(), $data['archive_reason'] ?? null)),
                Tables\Actions\Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (OperationalFormRecord $record): bool => self::canDelete($record) && ($record->isArchived() || $record->trashed()))
                    ->action(fn (OperationalFormRecord $record) => app(OperationalEvidenceArchiveService::class)->restore($record, auth()->user())),
                Tables\Actions\Action::make('delete')
                    ->label('Move to Trash')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Move this form to Trash?')
                    ->modalDescription('The form leaves active work. Documents and version history are retained and can be restored from the Trash filter.')
                    ->visible(fn (OperationalFormRecord $record): bool => self::canDelete($record) && ! $record->trashed())
                    ->action(fn (OperationalFormRecord $record) => app(OperationalFormDeletionService::class)->deleteRecord($record, auth()->user())),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('delete')
                    ->label('Move selected to Trash')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Move selected forms to Trash?')
                    ->modalDescription('Documents and version history are retained. Use the Trash filter to find and restore these forms.')
                    ->visible(fn (): bool => auth()->user()?->can('admin.forms.manage') ?? false)
                    ->action(function ($records): void {
                        $records->each(
                            fn (OperationalFormRecord $record) => app(OperationalFormDeletionService::class)->deleteRecord($record, auth()->user()),
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.forms.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('admin.forms.manage') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationalFormRecords::route('/'),
            'view' => Pages\ViewOperationalFormRecord::route('/{record}'),
        ];
    }
}
