<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\DefectResource\Pages;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\DefectServiceTicketActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectFollowUpState;
use App\Support\Fleet\DefectNoActionClosure;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DefectResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = ApparatusDefect::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Defects';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $modelLabel = 'Equipment Issue';

    protected static ?string $pluralModelLabel = 'Missing / Damaged Equipment';

    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // What the report is about (which unit, where, which item, how it failed) is the record's
                // identity. Readiness counts, continuity matching and notifications all key on it, and an edit
                // leaves no event, actor or reason, so on edit it is shown but never written from this form.
                // A report entered against the wrong unit or item is closed with Close — No Action Required.
                Forms\Components\Select::make('apparatus_id')
                    ->relationship('apparatus', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Apparatus $record): string => $record->name ?: $record->designation ?: $record->vehicle_number ?: (string) $record->getKey())
                    ->required()
                    ->searchable()
                    ->preload()
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),

                Forms\Components\TextInput::make('compartment')
                    ->required()
                    ->maxLength(255)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),

                Forms\Components\TextInput::make('item')
                    ->required()
                    ->maxLength(255)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),

                Forms\Components\Select::make('issue_type')
                    ->required()
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ])
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),

                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull()
                    ->rows(3),

                Forms\Components\FileUpload::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->directory('defects')
                    ->image()
                    ->imageEditor()
                    ->columnSpanFull(),

                // The lifecycle is changed only by the corrective-action workflow (the inspection page
                // or the Mark Resolved action), never by editing these fields, so they are display-only.
                // Shown from action_status so "Closed — no action required" is never read as "Resolved".
                Forms\Components\Placeholder::make('lifecycle')
                    ->label('Status')
                    ->content(fn (?ApparatusDefect $record): string => $record?->attributedActionLabel() ?? DefectActionStatus::label(null))
                    ->helperText('Change it with the issue actions on the inspection page, or Mark Resolved in the list.'),

                Forms\Components\Textarea::make('resolution_notes')
                    ->label(fn (?ApparatusDefect $record): string => $record?->isClosedNoAction()
                        ? DefectNoActionClosure::REASON_LABEL
                        : 'Resolution notes')
                    ->columnSpanFull()
                    ->rows(3)
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (?ApparatusDefect $record): bool => (bool) $record?->resolved),

                Forms\Components\DateTimePicker::make('resolved_at')
                    ->label(fn (?ApparatusDefect $record): string => $record?->isClosedNoAction() ? 'Closed at' : 'Resolved at')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (?ApparatusDefect $record): bool => (bool) $record?->resolved),
            ]);
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['serviceTicket', 'latestStatusEvent.actor:id,name,display_name']))
            ->columns([
                Tables\Columns\TextColumn::make('apparatus.name')
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(fn (ApparatusDefect $record): string => $record->compartment.' / '.$record->item.' · '.str($record->issue_type)->headline().' · '.$record->attributedActionLabel())
                    ->label('Apparatus')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\ImageColumn::make('photo_path')->visibleFrom('md')
                    ->label('Photo')
                    ->getStateUsing(fn (ApparatusDefect $record): ?string => $record->availablePhotoPath())
                    ->disk('public')
                    ->width(60)
                    ->height(60),

                Tables\Columns\TextColumn::make('photo_availability')->visibleFrom('md')
                    ->label('Photo status')
                    ->getStateUsing(fn (ApparatusDefect $record): ?string => $record->hasMissingPhotoReference()
                        ? 'Photo unavailable'
                        : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('compartment')->visibleFrom('md')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('item')->visibleFrom('md')
                    ->searchable(),

                // From action_status: "Resolved" (fixed) and "Closed — no action required" are different outcomes.
                Tables\Columns\TextColumn::make('action_status')->visibleFrom('md')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ApparatusDefect $record): string => $record->attributedActionLabel())
                    ->color(fn (?string $state): string => match ($state) {
                        DefectActionStatus::RESOLVED => 'success',
                        DefectActionStatus::DISMISSED => 'gray',
                        DefectActionStatus::REPORTED, null => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('follow_up')->visibleFrom('md')
                    ->label('Follow-up')
                    ->badge()
                    ->getStateUsing(fn (ApparatusDefect $record): string => DefectFollowUpState::for($record))
                    ->formatStateUsing(fn (string $state): string => DefectFollowUpState::label($state))
                    ->color(fn (string $state): string => DefectFollowUpState::color($state)),

                Tables\Columns\TextColumn::make('serviceTicket.ticket_number')->visibleFrom('md')
                    ->label('Ticket')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('issue_type')->visibleFrom('md')
                    ->label('Issue Type')
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state))),

                Tables\Columns\TextColumn::make('reported_date')->visibleFrom('md')
                    ->label('Reported Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('resolved_at')->visibleFrom('md')
                    ->label('Closed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // `open` here means "reported, not yet acknowledged"; it is a narrowing filter,
                // not the default, so acknowledged / scheduled issues are never hidden.
                SelectFilter::make('status')
                    ->label('Acknowledgement')
                    ->options([
                        'open' => 'Not yet acknowledged',
                        'in_progress' => 'In progress',
                    ]),

                // Exact lifecycle stage. "Resolved" is a repair; "Closed — no action required" is not.
                // Choosing one replaces the Open / Closed view below, so asking for a closed status is
                // never silently combined with "Open" into an empty list.
                SelectFilter::make('action_status')
                    ->label('Exact status')
                    ->placeholder('Any')
                    ->options(DefectActionStatus::labels()),

                // Default view: all open work (reported, acknowledged, action pending, replacement
                // available, repair scheduled). Matches the station profile's "Unresolved equipment
                // issues" count (resolved = false). "All" shows closed issues too.
                Tables\Filters\TernaryFilter::make('unresolved')
                    ->label('Open or closed')
                    ->placeholder('All')
                    ->default(true)
                    ->trueLabel('Open (reported, in progress)')
                    ->falseLabel('Closed (resolved or no action required)')
                    ->query(function (Builder $query, array $data, mixed $livewire): Builder {
                        if (blank($data['value'] ?? null) || self::exactStatusChosen($livewire)) {
                            return $query;
                        }

                        return $query->where('resolved', ! $data['value']);
                    }),

                SelectFilter::make('follow_up')
                    ->label('Follow-up')
                    ->options(DefectFollowUpState::options())
                    ->query(fn (Builder $query, array $data): Builder => DefectFollowUpState::applyFilter(
                        $query,
                        $data['value'] ?? null,
                    )),

                SelectFilter::make('issue_type')
                    ->label('Issue Type')
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ]),

                SelectFilter::make('station_id')
                    ->relationship('apparatus.station', 'station_number')
                    ->label('Station'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    DefectServiceTicketActions::configureView(
                        Tables\Actions\Action::make(DefectServiceTicketActions::VIEW),
                        fn (Tables\Actions\Action $action): ?ApparatusDefect => DefectServiceTicketActions::defectFrom($action->getRecord()),
                    ),
                    DefectServiceTicketActions::configureCreate(
                        Tables\Actions\Action::make(DefectServiceTicketActions::CREATE),
                        fn (Tables\Actions\Action $action): ?ApparatusDefect => DefectServiceTicketActions::defectFrom($action->getRecord()),
                    ),

                    Tables\Actions\Action::make('source_inspection')
                        ->label('Source inspection')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->visible(fn (ApparatusDefect $record): bool => self::sourceInspectionId($record) !== null
                            && (auth()->user()?->can('viewAny', ApparatusInspection::class) ?? false))
                        ->url(fn (ApparatusDefect $record): string => InspectionWorkspace::url(
                            (int) self::sourceInspectionId($record),
                            'issue-'.$record->getKey(),
                        )),

                    // Resolution goes through the corrective-action service: it records the event,
                    // tells the people who reported or confirmed the issue, and requires a type and notes.
                    DefectCorrectiveActions::resolve(
                        Tables\Actions\Action::make('mark_resolved'),
                        fn (MountableAction $action): ?ApparatusDefect => $action instanceof Tables\Actions\Action
                            ? DefectServiceTicketActions::defectFrom($action->getRecord())
                            : null,
                    )
                        ->label('Mark Resolved')
                        ->tooltip('Resolve this equipment issue')
                        ->visible(fn (Tables\Actions\Action $action): bool => DefectCorrectiveActions::canManage(auth()->user())
                            && ($defect = DefectServiceTicketActions::defectFrom($action->getRecord())) instanceof ApparatusDefect
                            && ! DefectActionStatus::isClosed($defect->action_status)),

                    Tables\Actions\EditAction::make(),
                ])->label('Actions')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            ])
            ->defaultSort('reported_date', 'desc'));
    }

    /** True while the "Exact status" filter has a value; that choice replaces the Open / Closed view. */
    private static function exactStatusChosen(mixed $livewire): bool
    {
        return is_object($livewire) && filled(data_get($livewire->tableFilters ?? [], 'action_status.value'));
    }

    /** The inspection that first reported this issue, else the one that created the record, else the latest to observe it. */
    private static function sourceInspectionId(ApparatusDefect $defect): ?int
    {
        $id = $defect->first_reported_inspection_id ?? $defect->apparatus_inspection_id ?? $defect->last_observed_inspection_id;

        return $id === null ? null : (int) $id;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDefects::route('/'),
            'edit' => Pages\EditDefect::route('/{record}/edit'),
        ];
    }
}
