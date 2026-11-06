<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\StationSupplyRequestResource\Pages;
use App\Filament\Support\ArchiveFilter;
use App\Filament\Support\SubmissionConversationActions;
use App\Models\Station;
use App\Models\StationInventoryAudit;
use App\Models\StationSupplyRequest;
use App\Services\StationSupplyRequestWorkflowService;
use App\Services\SupportTriage\EquipmentClassification;
use App\Services\SupportTriage\TriageProjector;
use App\Services\SupportTriage\TriageSql;
use App\Support\DecisionReason;
use App\Support\HubTimezone;
use App\Support\MemberReplies\ReplySubject;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One list of every station's supply requests for staff: what was asked, its Low / Medium / High priority,
 * the automatic receipt the member was sent, and what staff have answered. The per-station tab on the
 * Station page stays; both call StationSupplyRequestWorkflowService, so there is only one workflow.
 */
class StationSupplyRequestResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = StationSupplyRequest::class;

    // No icon: the Stations navigation group already carries one.
    protected static ?string $navigationGroup = 'Stations';

    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Supply Requests';

    protected static ?string $modelLabel = 'supply request';

    protected static ?string $pluralModelLabel = 'supply requests';

    protected static ?string $slug = 'station-supply-requests';

    /** @var \WeakMap<object, bool>|null */
    private static ?\WeakMap $manageMemo = null;

    private const STATUSES = [
        'open' => 'Open',
        'ordered' => 'Ordered',
        'replenished' => 'Replenished',
        'denied' => 'Denied',
    ];

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            // The priority is read with the rows (one scalar subquery on the projection), never per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select('station_supply_requests.*')
                ->selectRaw(TriageSql::level(TriageProjector::SUPPLY_REQUEST, 'station_supply_requests.id').' AS priority_level'))
            ->columns([
                Tables\Columns\TextColumn::make('request_text')
                    ->label('Request')
                    ->wrap()
                    ->limit(120)
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(fn (StationSupplyRequest $record): string => self::stationLabel($record)
                        .' · '.(self::STATUSES[$record->status] ?? ucfirst($record->status))
                        .' · '.self::priorityLabel($record->getAttribute('priority_level')).' priority'
                        .($record->created_by_name ? ' · '.$record->created_by_name : ''))
                    ->searchable(['request_text', 'created_by_name']),
                Tables\Columns\TextColumn::make('station.station_number')->visibleFrom('md')
                    ->label('Station')
                    ->formatStateUsing(fn (mixed $state): string => 'Station '.$state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->visibleFrom('md')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'warning',
                        'ordered' => 'info',
                        'replenished' => 'success',
                        'denied' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority_level')->visibleFrom('md')
                    ->label('Priority')
                    ->badge()
                    ->placeholder('Not set')
                    ->formatStateUsing(fn (?string $state): string => self::priorityLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        EquipmentClassification::HIGH => 'danger',
                        EquipmentClassification::MEDIUM => 'warning',
                        EquipmentClassification::LOW => 'success',
                        default => 'gray',
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderByRaw('CASE '.TriageSql::level(TriageProjector::SUPPLY_REQUEST, 'station_supply_requests.id')
                            ." WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END {$direction}");
                    }),
                Tables\Columns\TextColumn::make('ai_receipt_text')->visibleFrom('md')
                    ->label('Automated reply sent')
                    ->wrap()
                    ->limit(90)
                    ->placeholder('None sent')
                    ->description(fn (StationSupplyRequest $record): ?string => $record->ai_receipt_at
                        ? 'Support Services (automated) · '.$record->ai_receipt_at->timezone(HubTimezone::NAME)->format('M j, g:i A')
                        : null),
                Tables\Columns\TextColumn::make('public_response')->visibleFrom('md')
                    ->label('Staff response')
                    ->wrap()
                    ->limit(90)
                    ->placeholder('No staff response yet'),
                Tables\Columns\TextColumn::make('created_by_name')->visibleFrom('md')
                    ->label('Submitted by')
                    ->description(fn (StationSupplyRequest $record): ?string => $record->created_by_shift ? 'Shift '.$record->created_by_shift : null)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')->visibleFrom('md')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->timezone(HubTimezone::NAME)
                    ->sortable(),
            ])
            // Open requests first, then newest first.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw("CASE WHEN station_supply_requests.status = 'open' AND station_supply_requests.archived_at IS NULL THEN 0 ELSE 1 END")
                ->orderByDesc('station_supply_requests.created_at'))
            ->filters([
                ArchiveFilter::make(),
                Tables\Filters\SelectFilter::make('status')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('station_id')
                    ->relationship('station', 'station_number')
                    ->getOptionLabelFromRecordUsing(fn (Station $record): string => 'Station '.$record->station_number)
                    ->label('Station'),
                Tables\Filters\SelectFilter::make('priority_level')
                    ->label('Priority')
                    ->options([
                        EquipmentClassification::LOW => 'Low',
                        EquipmentClassification::MEDIUM => 'Medium',
                        EquipmentClassification::HIGH => 'High',
                        'not_set' => 'Not set',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'not_set' => $query->whereRaw(TriageSql::level(TriageProjector::SUPPLY_REQUEST, 'station_supply_requests.id').' IS NULL'),
                        default => $query->whereRaw(TriageSql::level(TriageProjector::SUPPLY_REQUEST, 'station_supply_requests.id').' = ?', [$data['value']]),
                    }),
            ])
            ->actions(self::rowActions())
            ->bulkActions([]));
    }

    /**
     * The same staff actions as the Station page's Supply Requests tab, calling the same workflow service
     * (it re-checks admin.stations.manage itself). No new states.
     *
     * @return list<Tables\Actions\Action|Tables\Actions\ActionGroup>
     */
    private static function rowActions(): array
    {
        return [
            Tables\Actions\ViewAction::make(),
            SubmissionConversationActions::row(ReplySubject::STATION_SUPPLY_REQUEST),
            Tables\Actions\EditAction::make()
                ->visible(fn (StationSupplyRequest $record): bool => self::canManage() && ! $record->isArchived())
                ->using(function (StationSupplyRequest $record, array $data): StationSupplyRequest {
                    app(StationSupplyRequestWorkflowService::class)->update($record, auth()->user(), $data);

                    return $record;
                })
                ->form([
                    Forms\Components\Select::make('status')
                        ->options(self::STATUSES)
                        ->required()
                        ->live(),
                    Forms\Components\Textarea::make('admin_notes')
                        ->label('Internal Notes')->maxLength(5000),
                    Forms\Components\Textarea::make('public_response')
                        ->label(fn (Forms\Get $get): string => $get('status') === 'denied' ? 'Reason for denying (shown to the member)' : 'Member response')
                        ->required(fn (Forms\Get $get, StationSupplyRequest $record): bool => $get('status') === 'denied' && $record->status !== 'denied')
                        ->minLength(fn (Forms\Get $get): ?int => $get('status') === 'denied' ? DecisionReason::MIN_LENGTH : null)
                        ->maxLength(5000),
                ]),
            Tables\Actions\Action::make('archive')
                ->label('Archive')->icon('heroicon-o-archive-box')->color('gray')
                ->visible(fn (StationSupplyRequest $record): bool => self::canManage() && ! $record->isArchived())
                ->form([Forms\Components\Textarea::make('reason')->label('Archive reason')->maxLength(2000)])
                ->action(fn (StationSupplyRequest $record, array $data) => app(StationSupplyRequestWorkflowService::class)->archive($record, auth()->user(), $data['reason'] ?? null)),
            Tables\Actions\Action::make('restore')
                ->label('Restore')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (StationSupplyRequest $record): bool => self::canManage() && $record->isArchived())
                ->action(fn (StationSupplyRequest $record) => app(StationSupplyRequestWorkflowService::class)->restore($record, auth()->user())),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('request_text')->label('Request')->columnSpanFull(),
            Infolists\Components\TextEntry::make('station.station_number')->label('Station')
                ->formatStateUsing(fn (mixed $state): string => 'Station '.$state),
            Infolists\Components\TextEntry::make('status')->badge()
                ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? ucfirst($state)),
            Infolists\Components\TextEntry::make('priority_level')->label('Priority')
                ->formatStateUsing(fn (?string $state): string => self::priorityLabel($state))
                ->badge()
                ->placeholder('Not set'),
            Infolists\Components\TextEntry::make('created_by_name')->label('Submitted by'),
            Infolists\Components\TextEntry::make('created_at')->label('Submitted')
                ->dateTime('M j, Y g:i A')->timezone(HubTimezone::NAME),
            Infolists\Components\TextEntry::make('public_response')->label('Staff response')
                ->placeholder('No staff response yet')->columnSpanFull(),
            Infolists\Components\TextEntry::make('ai_receipt_text')->label('Support Services (automated)')
                ->helperText('Automatic receipt only. Not a staff reply or approval.')
                ->placeholder('None sent')->columnSpanFull(),
            Infolists\Components\TextEntry::make('admin_notes')->label('Internal notes')
                ->placeholder('None')
                ->visible(fn (): bool => self::canManage())->columnSpanFull(),
            Infolists\Components\TextEntry::make('history')->label('History')
                ->state(fn (StationSupplyRequest $record): string => StationInventoryAudit::query()
                    ->where('station_id', $record->station_id)->where('to_value->request_id', $record->id)
                    ->orderBy('id')->get()->map(fn (StationInventoryAudit $event): string => $event->created_at->timezone(HubTimezone::NAME)->format('M j, Y g:i A').' · '.$event->actor_name.' · '.str($event->action)->headline()
                        .' · '.($event->to_value['status'] ?? '')
                    )->implode("\n"))
                ->placeholder('No history')->columnSpanFull(),
        ])->columns(2);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('station');
    }

    /** Asked once per signed-in user per request, not once per row action. */
    private static function canManage(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        self::$manageMemo ??= new \WeakMap;

        return self::$manageMemo[$user] ??= $user->can('admin.stations.manage');
    }

    private static function stationLabel(StationSupplyRequest $record): string
    {
        $station = $record->getRelationValue('station');

        return 'Station '.($station instanceof Station ? $station->station_number : 'unknown');
    }

    private static function priorityLabel(mixed $level): string
    {
        return is_string($level) && $level !== '' ? ucfirst($level) : 'Not set';
    }

    // Same staff access as the station request pages: admin.stations.view to see, admin.stations.manage to act.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.stations.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    // There is no edit page; this only authorizes the list's "Edit" dialog (the same status / response form
    // as the Station page's Supply Requests tab), which the workflow service re-checks.
    public static function canEdit(Model $record): bool
    {
        return self::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStationSupplyRequests::route('/'),
        ];
    }
}
