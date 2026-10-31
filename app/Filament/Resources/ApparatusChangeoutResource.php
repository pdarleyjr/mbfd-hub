<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\ApparatusChangeoutResource\Pages;
use App\Filament\Support\ApparatusChangeoutWording;
use App\Filament\Support\ApparatusFleetHistory;
use App\Models\ApparatusChangeout;
use App\Models\Station;
use App\Support\Fleet\FleetQueueCounts;
use App\Support\HubTimezone;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Vehicle changeouts": every move of a unit from one truck to another, with what the crew said about the equipment.
 * Viewing follows admin.fleet.view and deciding follows admin.fleet.manage, through the admin capability gate. Every
 * change goes through ApparatusChangeoutService. There is no create or edit page: crews start changeouts in the Hub.
 */
class ApparatusChangeoutResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = ApparatusChangeout::class;

    protected static ?string $slug = 'apparatus-changeouts';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 73;

    protected static ?string $navigationLabel = 'Vehicle changeouts';

    protected static ?string $modelLabel = 'vehicle changeout';

    protected static ?string $pluralModelLabel = 'vehicle changeouts';

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $pending = FleetQueueCounts::pendingChangeouts();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting on a Fleet decision';
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->columns(self::columns(showUnit: true))
            ->defaultSort(fn (Builder $query): Builder => self::pendingFirst($query))
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('handoff_status')
                    ->label('Equipment handoff')
                    ->options([
                        ApparatusChangeout::HANDOFF_COMPLETE => ApparatusChangeoutWording::handoffLabel(ApparatusChangeout::HANDOFF_COMPLETE),
                        ApparatusChangeout::HANDOFF_PENDING_VERIFICATION => ApparatusChangeoutWording::handoffLabel(ApparatusChangeout::HANDOFF_PENDING_VERIFICATION),
                    ]),
                Tables\Filters\SelectFilter::make('station_id')
                    ->label('Station')
                    ->options(fn (): array => Station::query()->orderBy('station_number')->get(['id', 'station_number'])
                        ->mapWithKeys(fn (Station $station): array => [$station->getKey() => 'Station '.$station->station_number])
                        ->all()),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Review')]));
    }

    /** Waiting on Fleet first, newest first inside each group. */
    public static function pendingFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ApparatusChangeout::STATUS_PENDING_FLEET])
            ->orderByDesc('id');
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect([
            ApparatusChangeout::STATUS_PENDING_FLEET,
            ApparatusChangeout::STATUS_COMPLETED,
            ApparatusChangeout::STATUS_REJECTED,
            ApparatusChangeout::STATUS_CANCELLED,
        ])->mapWithKeys(fn (string $status): array => [$status => ApparatusChangeout::statusLabel($status)])->all();
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            ApparatusChangeout::STATUS_PENDING_FLEET => 'warning',
            ApparatusChangeout::STATUS_COMPLETED => 'success',
            default => 'gray',
        };
    }

    public static function handoffColor(?string $handoff): string
    {
        return $handoff === ApparatusChangeout::HANDOFF_PENDING_VERIFICATION ? 'warning' : 'success';
    }

    /** @return list<Tables\Columns\Column> */
    public static function columns(bool $showUnit): array
    {
        return [
            Tables\Columns\TextColumn::make('unit_designation')
                ->label($showUnit ? 'Unit' : 'Changeout')
                ->weight('bold')
                ->searchable(['unit_designation', 'from_vehicle_number', 'to_vehicle_number', 'submitted_by_name'])
                ->wrap()
                ->extraAttributes(['class' => 'hub-phone-summary'])
                ->description(fn (ApparatusChangeout $record): string => 'Truck '.($record->from_vehicle_number ?: 'not recorded').' to truck '.$record->to_vehicle_number.'. '
                    .ApparatusChangeout::statusLabel($record->status).'. '.ApparatusChangeoutWording::handoffLabel($record->handoff_status).'.'),
            Tables\Columns\TextColumn::make('from_vehicle_number')->label('From truck')->visibleFrom('md')->placeholder('Not recorded')->extraAttributes(['class' => 'tabular-nums']),
            Tables\Columns\TextColumn::make('to_vehicle_number')->label('To truck')->visibleFrom('md')->weight('bold')->extraAttributes(['class' => 'tabular-nums']),
            Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->visibleFrom('md')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ApparatusChangeout::statusLabel($state))
                ->color(fn (?string $state): string => self::statusColor($state)),
            Tables\Columns\TextColumn::make('handoff_status')
                ->label('Equipment')
                ->visibleFrom('md')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ApparatusChangeoutWording::handoffLabel($state))
                ->color(fn (?string $state): string => self::handoffColor($state)),
            Tables\Columns\TextColumn::make('submitted_by_name')->label('Submitted by')->visibleFrom('md'),
            Tables\Columns\TextColumn::make('created_at')
                ->label('Submitted')
                ->visibleFrom('md')
                ->dateTime('M j, Y g:i A', HubTimezone::NAME)
                ->sortable(),
            Tables\Columns\TextColumn::make('effective_at')
                ->label('Took effect')
                ->visibleFrom('md')
                ->dateTime('M j, Y g:i A', HubTimezone::NAME)
                ->placeholder('Not yet')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Corrections stayed on the old truck')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (ApparatusChangeout $record): bool => ApparatusChangeoutWording::correctionsLeft($record) > 0)
                ->schema([
                    Infolists\Components\TextEntry::make('corrections_left')
                        ->hiddenLabel()
                        ->state(fn (ApparatusChangeout $record): string => ApparatusChangeoutWording::correctionsWarning($record)),
                ]),
            Infolists\Components\Section::make('Changeout')
                ->description(fn (ApparatusChangeout $record): ?string => ApparatusChangeoutWording::stateNote($record))
                ->schema([
                    Infolists\Components\TextEntry::make('unit_designation')->label('Unit')->weight(FontWeight::Bold),
                    Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn (string $state): string => ApparatusChangeout::statusLabel($state))
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('handoff_status')->label('Equipment handoff')->badge()
                        ->formatStateUsing(fn (string $state): string => ApparatusChangeoutWording::handoffLabel($state))
                        ->color(fn (?string $state): string => self::handoffColor($state)),
                    Infolists\Components\TextEntry::make('from_vehicle_number')
                        ->label('From truck')
                        ->placeholder('Not recorded')
                        ->url(fn (ApparatusChangeout $record): string => ApparatusResource::getUrl('view', ['record' => $record->from_apparatus_id]))
                        ->color('primary'),
                    Infolists\Components\TextEntry::make('to_vehicle_number')
                        ->label('To truck')
                        ->helperText(fn (ApparatusChangeout $record): ?string => $record->to_apparatus_id === null ? 'This number is not on a truck record yet.' : null)
                        ->url(fn (ApparatusChangeout $record): ?string => $record->to_apparatus_id === null
                            ? null
                            : ApparatusResource::getUrl('view', ['record' => $record->to_apparatus_id]))
                        ->color(fn (ApparatusChangeout $record): ?string => $record->to_apparatus_id === null ? null : 'primary'),
                    Infolists\Components\TextEntry::make('effective_at')->label('Took effect')->dateTime('M j, Y g:i A', HubTimezone::NAME)->placeholder('Not yet'),
                    Infolists\Components\TextEntry::make('submitted_by_name')->label('Submitted by'),
                    Infolists\Components\TextEntry::make('created_at')->label('Submitted')->dateTime('M j, Y g:i A', HubTimezone::NAME),
                    Infolists\Components\TextEntry::make('note')->label('Crew note')->placeholder('None'),
                    Infolists\Components\TextEntry::make('alert.title')
                        ->label('From alert')
                        ->placeholder('Not from an alert')
                        ->url(fn (ApparatusChangeout $record): ?string => $record->alert_id === null
                            ? null
                            : ApparatusOperationalAlertResource::getUrl('view', ['record' => $record->alert_id]))
                        ->color('primary'),
                    Infolists\Components\TextEntry::make('return_of_changeout_id')
                        ->label('Reverses')
                        ->placeholder('Not a return')
                        ->formatStateUsing(fn (int|string $state): string => 'Changeout '.$state)
                        ->url(fn (ApparatusChangeout $record): ?string => $record->return_of_changeout_id === null
                            ? null
                            : self::getUrl('view', ['record' => $record->return_of_changeout_id]))
                        ->color('primary'),
                ])->columns(3),
            Infolists\Components\Section::make('Fleet decision')
                ->visible(fn (ApparatusChangeout $record): bool => $record->decided_at !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('decidedBy.name')->label('Decided by')->placeholder('Not recorded'),
                    Infolists\Components\TextEntry::make('decided_at')->label('When')->dateTime('M j, Y g:i A', HubTimezone::NAME),
                    Infolists\Components\TextEntry::make('decision_note')->label('Note')->placeholder('None'),
                ])->columns(3),
            self::lineSection('Exceptions', ApparatusChangeoutWording::GROUP_EXCEPTIONS, 'Lines left behind, reported missing or damaged, partly moved, or with a note.', collapsed: false),
            self::lineSection('Still open', ApparatusChangeoutWording::GROUP_OPEN, 'Lines waiting on a person. The handoff stays open until each one is settled.', collapsed: false),
            self::lineSection('Moved', ApparatusChangeoutWording::GROUP_MOVED, 'Lines that went to the new truck as expected.', collapsed: true),
            Infolists\Components\Section::make('What changed on the trucks')
                ->visible(fn (ApparatusChangeout $record): bool => ApparatusChangeoutWording::moveLines($record) !== [])
                ->schema([
                    Infolists\Components\TextEntry::make('move_lines')
                        ->hiddenLabel()
                        ->state(fn (ApparatusChangeout $record): array => ApparatusChangeoutWording::moveLines($record))
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
            Infolists\Components\Section::make('History')
                ->schema([
                    Infolists\Components\TextEntry::make('history')
                        ->hiddenLabel()
                        ->placeholder('No history yet.')
                        ->state(fn (ApparatusChangeout $record): array => ApparatusFleetHistory::lines($record->events()->get()))
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
        ]);
    }

    private static function lineSection(string $heading, string $group, string $description, bool $collapsed): Infolists\Components\Section
    {
        return Infolists\Components\Section::make(fn (ApparatusChangeout $record): string => $heading.' ('.count(ApparatusChangeoutWording::lines($record)[$group]).')')
            ->description($description)
            ->collapsible()
            ->collapsed($collapsed)
            ->visible(fn (ApparatusChangeout $record): bool => ApparatusChangeoutWording::lines($record)[$group] !== [])
            ->schema([
                Infolists\Components\TextEntry::make('lines_'.$group)
                    ->hiddenLabel()
                    ->state(fn (ApparatusChangeout $record): array => ApparatusChangeoutWording::lines($record)[$group])
                    ->listWithLineBreaks()
                    ->bulleted(),
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ApparatusChangeout::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
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
            'index' => Pages\ListApparatusChangeouts::route('/'),
            'view' => Pages\ViewApparatusChangeout::route('/{record}'),
        ];
    }
}
