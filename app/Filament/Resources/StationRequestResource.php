<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\StationRequestStatus;
use App\Enums\StationRequestType;
use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\StationRequestResource\Pages;
use App\Models\StationRequest;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StationRequestResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = StationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Station Management';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Station Requests';

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('request_number')
                    ->label('Request')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('station.station_number')
                    ->label('Station')
                    ->sortable(),
                Tables\Columns\TextColumn::make('room.name')
                    ->label('Room')
                    ->formatStateUsing(fn (?string $state, StationRequest $record): string => $state ?: $record->room_name_snapshot ?: 'Station-wide')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $nested) use ($search): void {
                        $nested->where('room_name_snapshot', 'like', "%{$search}%")
                            ->orWhereHas('room', fn (Builder $roomQuery): Builder => $roomQuery->where('name', 'like', "%{$search}%"));
                    }))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('request_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StationRequestType::from($state)->label())
                    ->color(fn (string $state): string => $state === 'repair_service' ? 'warning' : 'primary'),
                Tables\Columns\TextColumn::make('title')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $nested) use ($search): void {
                        $nested->where('title', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('requester_name_snapshot', 'like', "%{$search}%")
                            ->orWhereHas('items', fn (Builder $itemQuery): Builder => $itemQuery->where('item_name', 'like', "%{$search}%"));
                    }))
                    ->limit(45),
                Tables\Columns\TextColumn::make('items.item_name')
                    ->label('Items')
                    ->bulleted()
                    ->limitList(2)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('requester_name_snapshot')
                    ->label('Requested By')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'normal' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StationRequestStatus::from($state)->label())
                    ->color(fn (string $state): string => StationRequestStatus::from($state)->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label('Assigned To / Vendor')
                    ->formatStateUsing(fn (?string $state, StationRequest $record): string => $state ?: $record->assigned_vendor ?: 'Unassigned')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('station_id')
                    ->relationship('station', 'station_number')
                    ->label('Station'),
                Tables\Filters\SelectFilter::make('room_id')
                    ->relationship('room', 'name')
                    ->label('Room')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('request_type')
                    ->options(collect(StationRequestType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(StationRequestStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()),
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'critical' => 'Critical',
                        'high' => 'High',
                        'normal' => 'Normal',
                        'low' => 'Low',
                    ]),
                Tables\Filters\TernaryFilter::make('open')
                    ->label('Open requests')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('status', StationRequestStatus::openValues()),
                        false: fn (Builder $query): Builder => $query->whereIn('status', StationRequestStatus::terminalValues()),
                    )
                    ->default(true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Request')
                ->schema([
                    Infolists\Components\TextEntry::make('request_number')->label('Request'),
                    Infolists\Components\TextEntry::make('station.station_number')->label('Station'),
                    Infolists\Components\TextEntry::make('room.name')
                        ->formatStateUsing(fn (?string $state, StationRequest $record): string => $state ?: $record->room_name_snapshot ?: 'Station-wide'),
                    Infolists\Components\TextEntry::make('request_type')
                        ->formatStateUsing(fn (string $state): string => StationRequestType::from($state)->label()),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => StationRequestStatus::from($state)->label())
                        ->color(fn (string $state): string => StationRequestStatus::from($state)->color()),
                    Infolists\Components\TextEntry::make('priority')->badge(),
                    Infolists\Components\TextEntry::make('requester_name_snapshot')->label('Requested By'),
                    Infolists\Components\TextEntry::make('requestedByEmployee.rank')->label('Rank'),
                    Infolists\Components\TextEntry::make('title')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('description')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('current_public_response')
                        ->label('Current Public Response')
                        ->placeholder('No public response yet')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('status_detail')
                        ->label('Internal Status Detail')
                        ->placeholder('None')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('Unassigned'),
                    Infolists\Components\TextEntry::make('assigned_vendor')->placeholder('None'),
                    Infolists\Components\TextEntry::make('acknowledgedBy.name')->label('Acknowledged By')->placeholder('Not acknowledged'),
                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    Infolists\Components\TextEntry::make('acknowledged_at')->dateTime()->placeholder('Not acknowledged'),
                    Infolists\Components\TextEntry::make('completed_at')->dateTime()->placeholder('Open'),
                ])->columns(3),
            Infolists\Components\Section::make('Requested Items')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('item_name')->label('Item'),
                            Infolists\Components\TextEntry::make('quantity'),
                            Infolists\Components\TextEntry::make('category')->placeholder('—'),
                            Infolists\Components\TextEntry::make('reason')->placeholder('—'),
                            Infolists\Components\TextEntry::make('requested_action')->placeholder('—'),
                            Infolists\Components\TextEntry::make('condition')->placeholder('—'),
                            Infolists\Components\TextEntry::make('roomAsset.name')->label('Linked Asset')->placeholder('None'),
                            Infolists\Components\TextEntry::make('pd_case_number')->label('PD Case #')->placeholder('—'),
                            Infolists\Components\ImageEntry::make('photo_path')
                                ->label('Photo')
                                ->disk('public')
                                ->height(120)
                                ->placeholder('No photo'),
                        ])->columns(4),
                ]),
            Infolists\Components\Section::make('Preserved Signatures')
                ->schema([
                    Infolists\Components\ImageEntry::make('metadata.signatures.member')
                        ->label('Member Signature')
                        ->disk('public')
                        ->height(140),
                    Infolists\Components\ImageEntry::make('metadata.signatures.officer')
                        ->label('Officer Signature')
                        ->disk('public')
                        ->height(140),
                ])
                ->columns(2)
                ->visible(fn (StationRequest $record): bool => filled(data_get($record->metadata, 'signatures.member')) || filled(data_get($record->metadata, 'signatures.officer'))),
            Infolists\Components\Section::make('Workflow History')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('updates')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('created_at')->dateTime()->label('When'),
                            Infolists\Components\TextEntry::make('status')->badge(),
                            Infolists\Components\TextEntry::make('changedBy.name')->label('Changed By')->placeholder('System'),
                            Infolists\Components\TextEntry::make('public_note')->label('Public Note')->placeholder('—')->columnSpan(2),
                            Infolists\Components\TextEntry::make('internal_note')->label('Internal Note')->placeholder('—')->columnSpan(2),
                        ])->columns(4),
                ]),
            Infolists\Components\Section::make('Asset Events')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('assetEvents')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('event_at')->dateTime(),
                            Infolists\Components\TextEntry::make('roomAsset.name')->label('Asset'),
                            Infolists\Components\TextEntry::make('event_type')->badge(),
                            Infolists\Components\TextEntry::make('vendor')->placeholder('—'),
                            Infolists\Components\TextEntry::make('cost')->money('USD')->placeholder('—'),
                            Infolists\Components\TextEntry::make('notes')->placeholder('—')->columnSpan(2),
                        ])->columns(4),
                ])->collapsible(),
            Infolists\Components\Section::make('Legacy Reference')
                ->schema([
                    Infolists\Components\TextEntry::make('legacy_source')->label('Source'),
                    Infolists\Components\TextEntry::make('legacy_id')->label('Legacy ID'),
                    Infolists\Components\TextEntry::make('metadata.legacy')
                        ->label('Preserved Metadata')
                        ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed()
                ->visible(fn (StationRequest $record): bool => filled($record->legacy_source)),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'station', 'room', 'requestedByEmployee', 'assignedTo',
            'acknowledgedBy',
            'items.roomAsset', 'updates.changedBy', 'assetEvents.roomAsset',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStationRequests::route('/'),
            'view' => Pages\ViewStationRequest::route('/{record}'),
        ];
    }
}
