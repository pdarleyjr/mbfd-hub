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
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Support\Fleet\ChangeoutAlertText;
use App\Support\Fleet\FleetQueueCounts;
use App\Support\HubTimezone;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Vehicle changeouts": every move of a unit from one truck to another, with what the crew took and what they left. It
 * is history: crews make these in the Hub and nobody approves them. Fleet acknowledges having seen each one. Viewing
 * follows admin.fleet.view and acknowledging follows admin.fleet.manage, through the admin capability gate.
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
        $unacknowledged = FleetQueueCounts::unacknowledgedChangeouts();

        return $unacknowledged > 0 ? (string) $unacknowledged : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Not yet acknowledged';
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->columns(self::columns(showUnit: true))
            ->defaultSort(fn (Builder $query): Builder => self::unacknowledgedFirst($query))
            ->filters([
                Tables\Filters\TernaryFilter::make('acknowledged_at')
                    ->label('Acknowledged')
                    ->nullable(),
                Tables\Filters\SelectFilter::make('station_id')
                    ->label('Station')
                    ->options(fn (): array => Station::query()->orderBy('station_number')->get(['id', 'station_number'])
                        ->mapWithKeys(fn (Station $station): array => [$station->getKey() => 'Station '.$station->station_number])
                        ->all()),
            ])
            ->actions([
                self::acknowledgeRowAction(),
                Tables\Actions\ViewAction::make()->label('View'),
            ]));
    }

    /** Not yet acknowledged first, newest first inside each group. */
    public static function unacknowledgedFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN acknowledged_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id');
    }

    private static function acknowledgeRowAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('acknowledge')
            ->label('Acknowledge')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (ApparatusChangeout $record): bool => ! $record->isAcknowledged() && (auth()->user()?->can('acknowledge', ApparatusChangeout::class) ?? false))
            ->action(function (ApparatusChangeout $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                try {
                    app(ApparatusChangeoutService::class)->acknowledge((int) $record->getKey(), $actor);
                } catch (AuthorizationException $exception) {
                    Notification::make()->danger()->title('You do not have permission to do this.')->body($exception->getMessage() ?: null)->send();

                    return;
                }
                Notification::make()->success()->title('Vehicle change acknowledged')->send();
            });
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
                    .ChangeoutAlertText::when($record).' by '.$record->submitted_by_name.'.'),
            Tables\Columns\TextColumn::make('from_vehicle_number')->label('From truck')->visibleFrom('md')->placeholder('Not recorded')->extraAttributes(['class' => 'tabular-nums']),
            Tables\Columns\TextColumn::make('to_vehicle_number')->label('To truck')->visibleFrom('md')->weight('bold')->extraAttributes(['class' => 'tabular-nums']),
            Tables\Columns\TextColumn::make('effective_at')
                ->label('When')
                ->visibleFrom('md')
                ->dateTime('M j, Y g:i A', HubTimezone::NAME)
                ->sortable(),
            Tables\Columns\TextColumn::make('submitted_by_name')->label('By')->visibleFrom('md'),
            Tables\Columns\TextColumn::make('acknowledged')
                ->label('Fleet')
                ->visibleFrom('md')
                ->badge()
                ->getStateUsing(fn (ApparatusChangeout $record): string => $record->isAcknowledged() ? 'Acknowledged' : 'Not yet acknowledged')
                ->color(fn (string $state): string => $state === 'Acknowledged' ? 'success' : 'warning'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Please review')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (ApparatusChangeout $record): bool => $record->reviewNotes() !== [])
                ->schema([
                    Infolists\Components\TextEntry::make('review_notes')
                        ->hiddenLabel()
                        ->state(fn (ApparatusChangeout $record): array => $record->reviewNotes())
                        ->listWithLineBreaks(),
                ]),
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
                ->description(fn (ApparatusChangeout $record): string => ChangeoutAlertText::oldTruckSentence($record))
                ->schema([
                    Infolists\Components\TextEntry::make('unit_designation')->label('Unit')->weight(FontWeight::Bold),
                    Infolists\Components\TextEntry::make('from_vehicle_number')
                        ->label('From truck')
                        ->placeholder('Not recorded')
                        ->url(fn (ApparatusChangeout $record): string => ApparatusResource::getUrl('view', ['record' => $record->from_apparatus_id]))
                        ->color('primary'),
                    Infolists\Components\TextEntry::make('to_vehicle_number')
                        ->label('To truck')
                        ->url(fn (ApparatusChangeout $record): ?string => $record->to_apparatus_id === null
                            ? null
                            : ApparatusResource::getUrl('view', ['record' => $record->to_apparatus_id]))
                        ->color(fn (ApparatusChangeout $record): ?string => $record->to_apparatus_id === null ? null : 'primary'),
                    Infolists\Components\TextEntry::make('effective_at')->label('When')->dateTime('M j, Y g:i A', HubTimezone::NAME),
                    Infolists\Components\TextEntry::make('submitted_by_name')->label('By'),
                    Infolists\Components\TextEntry::make('note')->label('Crew note')->placeholder('None'),
                    Infolists\Components\TextEntry::make('alert.title')
                        ->label('Scheduled by alert')
                        ->placeholder('Not scheduled')
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
                    Infolists\Components\TextEntry::make('acknowledged_state')
                        ->label('Fleet')
                        ->badge()
                        ->state(fn (ApparatusChangeout $record): string => $record->isAcknowledged()
                            ? 'Acknowledged by '.($record->acknowledgedBy->name ?? 'Fleet').' on '.$record->acknowledged_at?->setTimezone(HubTimezone::NAME)->format('M j, Y g:i A')
                            : 'Not yet acknowledged')
                        ->color(fn (ApparatusChangeout $record): string => $record->isAcknowledged() ? 'success' : 'warning'),
                ])->columns(3),
            self::lineSection('Left on the old truck', ApparatusChangeoutWording::GROUP_LEFT, collapsed: false),
            self::lineSection('Took to the new truck', ApparatusChangeoutWording::GROUP_TOOK, collapsed: true),
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

    private static function lineSection(string $heading, string $group, bool $collapsed): Infolists\Components\Section
    {
        return Infolists\Components\Section::make(fn (ApparatusChangeout $record): string => $heading.' ('.count(ApparatusChangeoutWording::lines($record)[$group]).')')
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
