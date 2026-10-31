<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\ApparatusOperationalAlertResource\Pages;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use App\Filament\Support\ApparatusFleetHistory;
use App\Models\Apparatus;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Support\Fleet\FleetQueueCounts;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Operational alerts": the one authoritative notice about a unit, which station pages, the apparatus profile and
 * Fleet all read. Viewing follows admin.fleet.view and changing follows admin.fleet.manage, through the admin
 * capability gate. Every change goes through ApparatusOperationalAlertService.
 */
class ApparatusOperationalAlertResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = ApparatusOperationalAlert::class;

    protected static ?string $slug = 'apparatus-operational-alerts';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 72;

    protected static ?string $navigationLabel = 'Operational alerts';

    protected static ?string $modelLabel = 'operational alert';

    protected static ?string $pluralModelLabel = 'operational alerts';

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $active = FleetQueueCounts::activeAlerts();

        return $active > 0 ? (string) $active : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Active alerts';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Who it is about')
                ->schema([
                    Forms\Components\Select::make('apparatus_id')
                        ->label('Unit')
                        ->helperText('The alert follows the unit if it moves to another truck.')
                        ->options(fn (): array => self::unitOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            if (blank($get('title'))) {
                                $set('title', self::defaultTitle(blank($state) ? null : Apparatus::query()->find($state), $get('category')));
                            }
                        }),
                    Forms\Components\Select::make('category')
                        ->label('Kind of alert')
                        ->options(ApparatusOperationalAlert::CATEGORIES)
                        ->default(ApparatusOperationalAlert::CATEGORY_GENERAL)
                        ->native(false)
                        ->required()
                        ->live()
                        ->disabledOn('edit'),
                    Forms\Components\Select::make('additional_station_ids')
                        ->label('Also show on these stations')
                        ->helperText('It always shows on the unit\'s own station.')
                        ->multiple()
                        ->options(fn (): array => self::stationOptions()),
                ])->columns(3),
            Forms\Components\Section::make('What crews read')
                ->schema(self::wordingFields()),
            Forms\Components\Section::make('When')
                ->description('A date with no time stays a date. Fill in the exact time only when Fleet gave one.')
                ->schema([
                    Forms\Components\DatePicker::make('scheduled_date')
                        ->label('Date')
                        ->required(fn (Get $get): bool => $get('category') === ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP),
                    self::windowField(),
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Exact time (Eastern)')
                        ->seconds(false),
                    Forms\Components\DatePicker::make('ends_on')
                        ->label('Stop showing after')
                        ->helperText('An upcoming vehicle change stays up until a changeout or Fleet closes it.')
                        ->visible(fn (Get $get): bool => $get('category') !== ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP),
                ])->columns(4),
            Forms\Components\Section::make('Vehicle change')
                ->visible(fn (Get $get): bool => $get('category') === ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP)
                ->schema([
                    Forms\Components\Select::make('planned_replacement_apparatus_id')
                        ->label('Planned replacement truck')
                        ->helperText('Optional. If you name a truck, a crew choosing a different one needs a Fleet decision.')
                        ->options(fn (Get $get): array => ApparatusWorkflowActions::truckOptions(blank($get('apparatus_id')) ? null : (int) $get('apparatus_id')))
                        ->searchable(),
                    self::oldTruckStatusField(),
                ])->columns(2),
            Forms\Components\Section::make('Related work')
                ->schema([
                    Forms\Components\Select::make('apparatus_service_ticket_id')
                        ->label('Service ticket')
                        ->options(fn (Get $get): array => self::ticketOptions(blank($get('apparatus_id')) ? null : (int) $get('apparatus_id')))
                        ->searchable()
                        ->placeholder('No ticket'),
                    Forms\Components\Toggle::make('send_notification')
                        ->label('Notify members')
                        ->helperText('Sends once, in the members\' alert bell.')
                        ->visibleOn('create'),
                ])->columns(2),
        ]);
    }

    /** @return list<Forms\Components\Component> */
    public static function wordingFields(): array
    {
        return [
            Forms\Components\TextInput::make('title')->label('Title')->required()->maxLength(180),
            Forms\Components\Textarea::make('message')->label('Message')->required()->rows(3)->maxLength(1000),
            Forms\Components\TextInput::make('reason')->label('Reason')->maxLength(180),
            Forms\Components\Textarea::make('instructions')->label('Instructions for the crew')->rows(2)->maxLength(1000),
        ];
    }

    /**
     * Form state on its way to the service. The time picker hands over the instant in the app time zone, while the
     * service reads a plain time string as an Eastern wall clock, so the instant is passed as an instant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forService(array $data): array
    {
        if (filled($data['scheduled_at'] ?? null) && is_string($data['scheduled_at'])) {
            $data['scheduled_at'] = CarbonImmutable::parse($data['scheduled_at'], (string) config('app.timezone'));
        }

        return $data;
    }

    public static function windowField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('scheduled_window')
            ->label('Part of the day')
            ->options(array_combine(ApparatusOperationalAlert::WINDOWS, array_map(ucfirst(...), ApparatusOperationalAlert::WINDOWS)))
            ->native(false)
            ->placeholder('No part of the day given');
    }

    public static function oldTruckStatusField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('old_truck_status_after')
            ->label('Old truck status after the change')
            ->helperText('If you leave this empty, the old truck takes the status the new truck had.')
            ->options(array_combine(ApparatusOperationalAlert::OLD_TRUCK_STATUSES, ApparatusOperationalAlert::OLD_TRUCK_STATUSES))
            ->native(false);
    }

    public static function defaultTitle(?Apparatus $unit, ?string $category): string
    {
        $name = $unit instanceof Apparatus ? ApparatusWorkflowActions::unitLabel($unit) : '';
        if ($name === '') {
            return '';
        }

        return $category === ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP
            ? 'Upcoming Apparatus Changeout: '.$name
            : (ApparatusOperationalAlert::CATEGORIES[$category ?? ''] ?? 'Operational notice').': '.$name;
    }

    /** @return array<int, string> every unit an alert can attach to, whether or not its truck has a number yet */
    private static function unitOptions(): array
    {
        return Apparatus::query()
            ->whereNotNull('slug')
            ->orderBy('designation')
            ->limit(300)
            ->get(['id', 'designation', 'name', 'unit_id', 'vehicle_number'])
            ->mapWithKeys(fn (Apparatus $unit): array => [$unit->getKey() => ApparatusWorkflowActions::unitLabel($unit).' · '.($unit->vehicle_number ?: 'no vehicle number')])
            ->all();
    }

    /** @return array<int, string> */
    public static function ticketOptions(?int $apparatusId): array
    {
        if ($apparatusId === null) {
            return [];
        }

        return ApparatusServiceTicket::query()
            ->where('apparatus_id', $apparatusId)
            ->open()
            ->latest('id')
            ->limit(50)
            ->get(['id', 'ticket_number', 'title'])
            ->mapWithKeys(fn (ApparatusServiceTicket $ticket): array => [$ticket->getKey() => trim($ticket->ticket_number.': '.$ticket->title)])
            ->all();
    }

    /** @return array<int, string> */
    private static function stationOptions(): array
    {
        return Station::query()->orderBy('station_number')->get(['id', 'station_number'])
            ->mapWithKeys(fn (Station $station): array => [$station->getKey() => 'Station '.$station->station_number])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->columns(self::columns(showUnit: true))
            ->defaultSort(fn (Builder $query): Builder => self::activeFirst($query))
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusLabels()),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kind of alert')
                    ->options(ApparatusOperationalAlert::CATEGORIES),
                Tables\Filters\SelectFilter::make('station')
                    ->label('Station')
                    ->options(fn (): array => self::stationOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->where(fn (Builder $inner): Builder => $inner
                            ->where('station_id', (int) $data['value'])
                            ->orWhereJsonContains('additional_station_ids', (int) $data['value']))),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Open')]));
    }

    /** Active first, newest first inside each group. */
    public static function activeFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ApparatusOperationalAlert::STATUS_ACTIVE])
            ->orderByDesc('id');
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            ApparatusOperationalAlert::STATUS_ACTIVE => 'Active',
            ApparatusOperationalAlert::STATUS_COMPLETED => 'Completed',
            ApparatusOperationalAlert::STATUS_CANCELLED => 'Cancelled',
            ApparatusOperationalAlert::STATUS_CLOSED => 'Closed',
        ];
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            ApparatusOperationalAlert::STATUS_ACTIVE => 'warning',
            ApparatusOperationalAlert::STATUS_COMPLETED => 'success',
            default => 'gray',
        };
    }

    /** @return list<Tables\Columns\Column> */
    public static function columns(bool $showUnit): array
    {
        return [
            Tables\Columns\TextColumn::make('title')
                ->label('Alert')
                ->weight('bold')
                ->searchable(['title', 'unit_designation', 'vehicle_number'])
                ->wrap()
                ->extraAttributes(['class' => 'hub-phone-summary'])
                ->description(fn (ApparatusOperationalAlert $record): string => implode('. ', array_filter([
                    $showUnit ? $record->unit_designation : null,
                    $record->categoryLabel(),
                    self::statusLabels()[$record->status] ?? $record->status,
                    $record->scheduleLabel(),
                ]))),
            Tables\Columns\TextColumn::make('unit_designation')->label('Unit')->visibleFrom('md')->visible($showUnit),
            Tables\Columns\TextColumn::make('category')
                ->label('Kind')
                ->visibleFrom('md')
                ->badge()
                ->color('gray')
                ->formatStateUsing(fn (string $state): string => ApparatusOperationalAlert::CATEGORIES[$state] ?? str($state)->headline()->toString()),
            Tables\Columns\TextColumn::make('schedule')
                ->label('When')
                ->visibleFrom('md')
                ->placeholder('No date set')
                ->getStateUsing(fn (ApparatusOperationalAlert $record): ?string => $record->scheduleLabel())
                ->description(fn (ApparatusOperationalAlert $record): ?string => $record->isActive() && $record->datePassed() ? 'The planned date has passed' : null),
            Tables\Columns\TextColumn::make('vehicle_number')->label('Truck when raised')->visibleFrom('md')->placeholder('Not recorded')->extraAttributes(['class' => 'tabular-nums']),
            Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->visibleFrom('md')
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? str($state)->headline()->toString())
                ->color(fn (?string $state): string => self::statusColor($state)),
            Tables\Columns\TextColumn::make('created_at')
                ->label('Created')
                ->visibleFrom('md')
                ->dateTime('M j, Y g:i A', HubTimezone::NAME)
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Alert')
                ->schema([
                    Infolists\Components\TextEntry::make('unit_designation')->label('Unit')->weight('bold'),
                    Infolists\Components\TextEntry::make('category')->label('Kind')->badge()->color('gray')
                        ->formatStateUsing(fn (string $state): string => ApparatusOperationalAlert::CATEGORIES[$state] ?? str($state)->headline()->toString()),
                    Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? str($state)->headline()->toString())
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('title')->label('Title')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('message')->label('Message')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('reason')->label('Reason')->placeholder('None given'),
                    Infolists\Components\TextEntry::make('instructions')->label('Instructions for the crew')->placeholder('None')->columnSpan(2),
                ])->columns(3),
            Infolists\Components\Section::make('When')
                ->schema([
                    Infolists\Components\TextEntry::make('schedule')
                        ->label('Scheduled')
                        ->placeholder('No date set')
                        ->state(fn (ApparatusOperationalAlert $record): ?string => $record->scheduleLabel())
                        ->helperText(fn (ApparatusOperationalAlert $record): ?string => $record->isActive() && $record->datePassed() ? 'The planned date has passed. This stays up until a changeout or Fleet closes it.' : null),
                    Infolists\Components\TextEntry::make('ends_on')->label('Stop showing after')->date('M j, Y')->placeholder('Not set'),
                    Infolists\Components\TextEntry::make('stations')
                        ->label('Shows on')
                        ->state(fn (ApparatusOperationalAlert $record): string => self::stationsLabel($record)),
                ])->columns(3),
            Infolists\Components\Section::make('Trucks and work')
                ->schema([
                    Infolists\Components\TextEntry::make('vehicle_number')
                        ->label('Truck when raised')
                        ->placeholder('Not recorded')
                        ->url(fn (ApparatusOperationalAlert $record): string => ApparatusResource::getUrl('view', ['record' => $record->apparatus_id]))
                        ->color('primary'),
                    Infolists\Components\TextEntry::make('plannedReplacement.vehicle_number')
                        ->label('Planned replacement truck')
                        ->placeholder('None named')
                        ->url(fn (ApparatusOperationalAlert $record): ?string => $record->planned_replacement_apparatus_id === null
                            ? null
                            : ApparatusResource::getUrl('view', ['record' => $record->planned_replacement_apparatus_id]))
                        ->color('primary'),
                    Infolists\Components\TextEntry::make('old_truck_status_after')->label('Old truck status after the change')->placeholder('The status the new truck had'),
                    Infolists\Components\TextEntry::make('serviceTicket.ticket_number')
                        ->label('Service ticket')
                        ->placeholder('No ticket')
                        ->url(fn (ApparatusOperationalAlert $record): ?string => $record->apparatus_service_ticket_id === null
                            ? null
                            : ApparatusServiceTicketResource::getUrl('view', ['record' => $record->apparatus_service_ticket_id]))
                        ->color('primary'),
                    Infolists\Components\TextEntry::make('completedByChangeout.id')
                        ->label('Completed by changeout')
                        ->placeholder('Not completed by a changeout')
                        ->formatStateUsing(fn (int|string $state): string => 'Changeout '.$state)
                        ->url(fn (ApparatusOperationalAlert $record): ?string => $record->completed_by_changeout_id === null
                            ? null
                            : ApparatusChangeoutResource::getUrl('view', ['record' => $record->completed_by_changeout_id]))
                        ->color('primary'),
                ])->columns(3),
            Infolists\Components\Section::make('Closing')
                ->visible(fn (ApparatusOperationalAlert $record): bool => ! $record->isActive())
                ->schema([
                    Infolists\Components\TextEntry::make('closedBy.name')->label('By')->placeholder('Not recorded'),
                    Infolists\Components\TextEntry::make('closed_at')->label('When')->dateTime('M j, Y g:i A', HubTimezone::NAME)->placeholder('Not recorded'),
                    Infolists\Components\TextEntry::make('close_note')->label('Note')->placeholder('None'),
                ])->columns(3),
            Infolists\Components\Section::make('History')
                ->schema([
                    Infolists\Components\TextEntry::make('history')
                        ->hiddenLabel()
                        ->placeholder('No history yet.')
                        ->state(fn (ApparatusOperationalAlert $record): array => ApparatusFleetHistory::lines($record->events()->get()))
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
        ]);
    }

    private static function stationsLabel(ApparatusOperationalAlert $record): string
    {
        $ids = array_values(array_unique(array_filter([$record->station_id, ...($record->additional_station_ids ?? [])])));
        if ($ids === []) {
            return 'No station';
        }

        return Station::query()->whereKey($ids)->orderBy('station_number')->pluck('station_number')
            ->map(fn ($number): string => 'Station '.$number)
            ->implode(', ');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ApparatusOperationalAlert::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', ApparatusOperationalAlert::class) ?? false;
    }

    /** A finished alert is a record of what happened, so only an active one can be edited. */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof ApparatusOperationalAlert
            && $record->isActive()
            && (auth()->user()?->can('update', $record) ?? false);
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
            'index' => Pages\ListApparatusOperationalAlerts::route('/'),
            'create' => Pages\CreateApparatusOperationalAlert::route('/create'),
            'view' => Pages\ViewApparatusOperationalAlert::route('/{record}'),
            'edit' => Pages\EditApparatusOperationalAlert::route('/{record}/edit'),
        ];
    }
}
