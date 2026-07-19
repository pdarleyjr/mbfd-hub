<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\OperationalFormRecordResource\Pages;
use App\Models\OperationalFormRecord;
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
                Tables\Columns\TextColumn::make('form_type')->label('Form')->badge()->formatStateUsing(fn (string $state) => $state === 'ics_214' ? 'ICS 214' : 'F-ROC Daily Activity Report')->color('info'),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(44),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'completed' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('last_autosaved_at')->label('Last saved')->since()->dateTimeTooltip()->sortable(),
                Tables\Columns\TextColumn::make('latest_pdf_version')->label('Latest PDF')->formatStateUsing(fn ($state) => $state ? "Version {$state}" : '—'),
                Tables\Columns\TextColumn::make('latestDocument.created_at')->label('PDF generated')->since()->dateTimeTooltip()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('form_type')->label('Form type')->options(['ics_214' => 'ICS 214', 'froc_log_001_ff' => 'F-ROC Daily Activity Report']),
                SelectFilter::make('status')->options(['draft' => 'Draft', 'completed' => 'Completed']),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([])
            ->defaultSort('updated_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']) ?? false;
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
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationalFormRecords::route('/'),
            'view' => Pages\ViewOperationalFormRecord::route('/{record}'),
        ];
    }
}
