<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasPageTableExportActions;
use App\Filament\Support\PersonnelTableSchema;
use App\Models\PersonnelTable;
use App\Models\User;
use App\Services\Exports\ExportableTable;
use App\Services\Personnel\PersonnelSource;
use App\Services\Personnel\PersonnelTableService;
use Filament\Actions\Action;
use Filament\Forms\Components as Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CustomPersonnelTables extends Page implements ExportableTable, HasTable
{
    use EnterpriseTable, InteractsWithTable;
    use HasPageTableExportActions;

    protected static ?string $navigationGroup = 'Personnel';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $title = 'Custom Personnel Tables';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.custom-personnel-tables';

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && app(PersonnelTableService::class)->canViewTables(auth()->user(), refreshActor: false);
    }

    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User && app(PersonnelTableService::class)->canViewTables(auth()->user()), 403);
    }

    public function table(Table $table): Table
    {
        return self::withExportActions(self::applyEnterpriseDefaults($table)->deferLoading(false)
            ->query(fn (): Builder => app(PersonnelTableService::class)->accessibleQuery(auth()->user())
                ->withTrashed()->whereNull('directory_key'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->wrap()
                    ->description(fn (PersonnelTable $record): ?string => $record->description)
                    ->url(fn (PersonnelTable $record): string => PersonnelTableEditor::getUrl(['table' => $record->id])),
                Tables\Columns\TextColumn::make('membership_mode')->label('Membership')->badge(),
                Tables\Columns\TextColumn::make('visibility')->label('Visibility')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('member_count')->label('Members')
                    ->state(fn (PersonnelTable $record): int => app(PersonnelTableService::class)->members(auth()->user(), $record)->count()),
                Tables\Columns\TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (PersonnelTable $record): string => $record->trashed() ? 'Deleted' : ($record->archived_at !== null ? 'Archived' : 'Active')),
                Tables\Columns\TextColumn::make('updated_at')->label('Modified')->since()->sortable(),
            ])->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('state')->form([
                    Forms\Select::make('value')->label('Status')->options(['active' => 'Active', 'archived' => 'Archived', 'deleted' => 'Deleted', 'all' => 'All'])->default('active'),
                ])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'active') {
                    'archived' => $query->whereNotNull('archived_at')->whereNull('deleted_at'),
                    'deleted' => $query->whereNotNull('deleted_at'),
                    'all' => $query,
                    default => $query->whereNull('archived_at')->whereNull('deleted_at'),
                }),
            ])->actions([
                Tables\Actions\Action::make('open')->label('Open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (PersonnelTable $record): string => PersonnelTableEditor::getUrl(['table' => $record->id])),
                Tables\Actions\Action::make('duplicate')->label('Duplicate')->icon('heroicon-o-document-duplicate')
                    ->visible(fn (PersonnelTable $record): bool => ! $record->trashed() && app(PersonnelTableService::class)->canCreate(auth()->user()))
                    ->form([Forms\Checkbox::make('include_data')->label('Also copy tracking values and highlights')->default(false)])
                    ->action(function (PersonnelTable $record, array $data): void {
                        $copy = app(PersonnelTableService::class)->duplicate(auth()->user(), $record, (bool) ($data['include_data'] ?? false));
                        $this->redirect(PersonnelTableEditor::getUrl(['table' => $copy->id]));
                    }),
                Tables\Actions\Action::make('restore')->label('Restore')->icon('heroicon-o-arrow-uturn-left')->requiresConfirmation()
                    ->visible(fn (PersonnelTable $record): bool => ($record->trashed() || $record->archived_at !== null)
                        && $this->canRestoreTable($record))
                    ->action(function (PersonnelTable $record): void {
                        app(PersonnelTableService::class)->restore(auth()->user(), $record, (int) $record->version);
                        Notification::make()->title('Table restored')->success()->send();
                    }),
            ])->emptyStateHeading('Create your first personnel table')
            ->emptyStateDescription('Choose members and add tracking columns for your administrative work.')
            ->searchPlaceholder('Search saved tables'));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('createTable')->label('Create Table')->icon('heroicon-o-plus')
            ->visible(fn (): bool => app(PersonnelTableService::class)->canCreate(auth()->user()))
            ->form([
                Forms\TextInput::make('name')->label('Table name')->required()->maxLength(100)->default('New Personnel Table'),
                Forms\Textarea::make('description')->rows(2)->maxLength(2000),
                Forms\Select::make('selection_mode')->label('Choose personnel')->options([
                    'all' => 'All current personnel', 'filter' => 'Choose by shift, rank, station or assignment',
                    'manual' => 'Select individual members', 'blank' => 'Start blank',
                ])->default('all')->required()->live(),
                Forms\Section::make('Member filters')->schema(PersonnelTableSchema::criteriaForm())->columns(2)
                    ->visible(fn (Get $get): bool => $get('selection_mode') === 'filter'),
                PersonnelTableSchema::memberSelect()->visible(fn (Get $get): bool => $get('selection_mode') === 'manual')->live(),
                Forms\Select::make('membership_mode')->label('Membership')->options([
                    'fixed' => 'Fixed — keep these selected members', 'dynamic' => 'Dynamic — update members when assignments change',
                ])->default('fixed')->required()->visible(fn (Get $get): bool => in_array($get('selection_mode'), ['all', 'filter'], true)),
                Forms\Placeholder::make('matching_count')->label('Matching members')
                    ->content(fn (Get $get): string => (string) $this->matchingCount($get)),
                PersonnelTableSchema::sourceColumnsForm(),
                Forms\Section::make('Tracking columns')->schema([PersonnelTableSchema::columnsForm()])->collapsible(),
                Forms\Section::make('Sharing')->schema([
                    Forms\Select::make('visibility')->options(['private' => 'Private', 'shared' => 'Shared with authorized administrators'])->default('private')->required(),
                ])->collapsed(),
            ])->modalSubmitActionLabel('Create Table')
            ->action(function (array $data): void {
                $mode = $data['selection_mode'];
                $criteria = $mode === 'filter' ? array_filter($data['criteria'] ?? [], fn ($value): bool => $value !== null && $value !== [] && $value !== '') : ['active_only' => true];
                $criteria['active_only'] ??= true;
                $membership = in_array($mode, ['all', 'filter'], true) ? ($data['membership_mode'] ?? 'fixed') : 'fixed';
                $ids = match ($mode) {
                    'manual' => $data['employee_ids'] ?? [],
                    'blank' => [],
                    default => $membership === 'fixed' ? app(PersonnelSource::class)->filter(app(PersonnelSource::class)->query(), $criteria)->pluck('employees.id')->all() : [],
                };
                $created = app(PersonnelTableService::class)->create(auth()->user(), [
                    'name' => $data['name'], 'description' => $data['description'] ?? null,
                    'visibility' => $data['visibility'] ?? 'private', 'membership_mode' => $membership,
                    'criteria' => $criteria, 'employee_ids' => $ids,
                    'source_columns' => $data['source_columns'], 'columns' => $data['columns'] ?? [],
                ]);
                $this->redirect(PersonnelTableEditor::getUrl(['table' => $created->id]));
            })];
    }

    private function matchingCount(Get $get): int
    {
        return match ($get('selection_mode')) {
            'manual' => count($get('employee_ids') ?? []),
            'blank' => 0,
            default => app(PersonnelSource::class)->filter(app(PersonnelSource::class)->query(),
                $get('selection_mode') === 'filter' ? ($get('criteria') ?? []) : ['active_only' => true])->count(),
        };
    }

    private function canRestoreTable(PersonnelTable $table): bool
    {
        return app(PersonnelTableService::class)->canArchive(auth()->user(), $table);
    }
}
