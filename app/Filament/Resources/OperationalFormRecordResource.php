<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\OperationalFormRecordResource\Pages;
use App\Models\OperationalFormRecord;
use App\Services\OperationalForms\OperationalFormDeletionService;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OperationalFormRecordResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = OperationalFormRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Active Operations';

    protected static ?string $navigationLabel = 'Forms';

    protected static ?string $modelLabel = 'Operational Form';

    protected static ?string $pluralModelLabel = 'Operational Forms';

    protected static ?string $slug = 'operational-forms';

    protected static ?int $navigationSort = 3;

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
                SelectFilter::make('form_type')->label('Form type')->options([
                    'ics_214' => 'ICS 214',
                    'froc_log_001_ff' => 'F-ROC Daily Activity Report',
                    'uploaded_file' => 'Submitted file',
                ]),
                SelectFilter::make('status')->options(['draft' => 'Draft', 'completed' => 'Completed']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this form and all files?')
                    ->modalDescription('This permanently deletes the record, every generated PDF or uploaded file, and its version history. This cannot be undone.')
                    ->action(fn (OperationalFormRecord $record) => app(OperationalFormDeletionService::class)->deleteRecord($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('delete')
                    ->label('Delete selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete selected forms and files?')
                    ->modalDescription('This permanently deletes every selected record and all associated files. This cannot be undone.')
                    ->action(function ($records): void {
                        $records->each(
                            fn (OperationalFormRecord $record) => app(OperationalFormDeletionService::class)->deleteRecord($record),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationalFormRecords::route('/'),
            'view' => Pages\ViewOperationalFormRecord::route('/{record}'),
        ];
    }
}
