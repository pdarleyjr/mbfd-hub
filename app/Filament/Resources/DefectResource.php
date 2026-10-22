<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\DefectResource\Pages;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\DefectServiceTicketActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectFollowUpState;
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
                Forms\Components\Select::make('apparatus_id')
                    ->relationship('apparatus', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Apparatus $record): string => $record->name ?: $record->designation ?: $record->vehicle_number ?: (string) $record->getKey())
                    ->required()
                    ->searchable()
                    ->preload(),

                Forms\Components\TextInput::make('compartment')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('item')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('issue_type')
                    ->required()
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ]),

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

                Forms\Components\Select::make('status')
                    ->required()
                    ->default('open')
                    ->options([
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved' => 'Resolved',
                    ]),

                Forms\Components\Textarea::make('resolution_notes')
                    ->columnSpanFull()
                    ->rows(3)
                    ->visible(fn (Forms\Get $get) => $get('status') === 'resolved'),

                Forms\Components\DateTimePicker::make('resolved_at')
                    ->visible(fn (Forms\Get $get) => $get('status') === 'resolved'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('serviceTicket'))
            ->columns([
                Tables\Columns\TextColumn::make('apparatus.name')
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(fn (ApparatusDefect $record): string => $record->compartment.' / '.$record->item.' · '.str($record->issue_type)->headline().' · '.str($record->status)->headline())
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

                Tables\Columns\TextColumn::make('status')->visibleFrom('md')
                    ->colors([
                        'danger' => 'open',
                        'warning' => 'in_progress',
                        'success' => 'resolved',
                    ]),

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
                    ->label('Resolved')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved' => 'Resolved',
                    ])
                    ->default('open'),

                // Matches the station profile's "Unresolved equipment issues"
                // count: open and in-progress issues (resolved = false).
                Tables\Filters\TernaryFilter::make('unresolved')
                    ->label('Unresolved')
                    ->trueLabel('Unresolved (open or in progress)')
                    ->falseLabel('Resolved')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('resolved', false),
                        false: fn (Builder $query): Builder => $query->where('resolved', true),
                    ),

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
            ->defaultSort('reported_date', 'desc');
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
