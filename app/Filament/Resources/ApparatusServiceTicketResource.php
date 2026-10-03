<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\ApparatusServiceTicketResource\Pages;
use App\Filament\Support\ArchiveFilter;
use App\Models\ApparatusServiceTicket;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ApparatusServiceTicketResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = ApparatusServiceTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Service Tickets';

    protected static ?string $modelLabel = 'Apparatus Service Ticket';

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('ticket_number')
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(fn (ApparatusServiceTicket $record): string => $record->unit_designation_snapshot.' · '.ApparatusServiceTicketStatus::from($record->status)->label().' · '.ucfirst($record->priority).' priority · '.$record->title)->label('Ticket')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit_designation_snapshot')->visibleFrom('md')->label('Unit')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('station.station_number')->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true)->label('Station')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('title')->visibleFrom('md')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $nested) use ($search): void {
                        $nested->where('title', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('requester_name_snapshot', 'like', "%{$search}%")
                            ->orWhere('assigned_vendor', 'like', "%{$search}%");
                    }))
                    ->limit(48),
                Tables\Columns\TextColumn::make('category')->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true)
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ApparatusServiceTicketCategory::options()[$state] ?? str($state)->headline()->toString()),
                Tables\Columns\TextColumn::make('priority')->visibleFrom('md')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'attention' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')->visibleFrom('md')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->label())
                    ->color(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('scheduled_for')->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true)->dateTime()->sortable()->placeholder('Not scheduled'),
                Tables\Columns\TextColumn::make('assignedTo.name')->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true)
                    ->label('Assigned To / Vendor')
                    ->formatStateUsing(fn (?string $state, ApparatusServiceTicket $record): string => $state ?: $record->assigned_vendor ?: 'Unassigned')
                    ->placeholder(fn (ApparatusServiceTicket $record): string => $record->assigned_vendor ?: 'Unassigned'),
                Tables\Columns\TextColumn::make('requester_name_snapshot')->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true)->label('Requested By')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true)->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true)->label('Last Updated')->dateTime()->sortable(),
            ])
            ->filters([
                ArchiveFilter::make(),
                Tables\Filters\SelectFilter::make('station_id')->relationship('station', 'station_number')->label('Station'),
                Tables\Filters\SelectFilter::make('apparatus_id')
                    ->relationship('apparatus', 'designation')
                    ->label('Unit')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('category')->options(ApparatusServiceTicketCategory::options()),
                Tables\Filters\SelectFilter::make('priority')->options(ApparatusServiceTicketPriority::options()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ApparatusServiceTicketStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()),
                Tables\Filters\TernaryFilter::make('open')
                    ->label('Open tickets')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('status', ApparatusServiceTicketStatus::openValues()),
                        false: fn (Builder $query): Builder => $query->whereIn('status', ApparatusServiceTicketStatus::terminalValues()),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Archive')->schema([
                Infolists\Components\TextEntry::make('archived_at')->dateTime(),
                Infolists\Components\TextEntry::make('archivedBy.name')->label('Archived by'),
                Infolists\Components\TextEntry::make('archive_reason')->placeholder('No reason recorded'),
            ])->columns(3)->visible(fn (ApparatusServiceTicket $record): bool => $record->isArchived()),
            Infolists\Components\Section::make('Service Ticket')
                ->schema([
                    Infolists\Components\TextEntry::make('ticket_number')->label('Ticket'),
                    Infolists\Components\TextEntry::make('unit_designation_snapshot')->label('Unit'),
                    Infolists\Components\TextEntry::make('station.station_number')->label('Origin Station')->placeholder('—'),
                    Infolists\Components\TextEntry::make('origin')->badge(),
                    Infolists\Components\TextEntry::make('category')
                        ->formatStateUsing(fn (string $state): string => ApparatusServiceTicketCategory::options()[$state] ?? str($state)->headline()->toString()),
                    Infolists\Components\TextEntry::make('priority')->badge(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->label())
                        ->color(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->color()),
                    Infolists\Components\TextEntry::make('requester_name_snapshot')->label('Requested By')->placeholder('Fleet'),
                    Infolists\Components\TextEntry::make('title')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('description')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('current_public_response')->label('Current Public Update')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('status_detail')->label('Internal Status Detail')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('Unassigned'),
                    Infolists\Components\TextEntry::make('assigned_vendor')->label('Vendor')->placeholder('None'),
                    Infolists\Components\TextEntry::make('service_type')->label('Service Type')->placeholder('—'),
                    Infolists\Components\TextEntry::make('scheduled_for')->dateTime()->placeholder('Not scheduled'),
                    Infolists\Components\TextEntry::make('scheduled_location')->label('Service Location')->placeholder('Not specified'),
                    Infolists\Components\TextEntry::make('expected_return_at')->label('Expected Return')->dateTime()->placeholder('Not specified'),
                    Infolists\Components\TextEntry::make('started_at')->dateTime()->placeholder('Not started'),
                    Infolists\Components\TextEntry::make('service_engine_hours')->suffix(' hrs')->placeholder('—'),
                    Infolists\Components\TextEntry::make('service_mileage')->suffix(' mi')->placeholder('—'),
                    Infolists\Components\TextEntry::make('opened_engine_hours')->label('Opened Hours')->suffix(' hrs')->placeholder('—'),
                    Infolists\Components\TextEntry::make('opened_miles')->label('Opened Miles')->suffix(' mi')->placeholder('—'),
                    Infolists\Components\TextEntry::make('completed_engine_hours')->label('Completed Hours')->suffix(' hrs')->placeholder('—'),
                    Infolists\Components\TextEntry::make('completed_miles')->label('Completed Miles')->suffix(' mi')->placeholder('—'),
                    Infolists\Components\TextEntry::make('resolution_summary')->label('Resolution')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    Infolists\Components\TextEntry::make('completed_at')->dateTime()->placeholder('Open'),
                ])->columns(3),
            Infolists\Components\Section::make('Append-only History')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('updates')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('created_at')->dateTime()->label('When'),
                            Infolists\Components\TextEntry::make('status')->badge(),
                            Infolists\Components\TextEntry::make('changedByUser.name')->label('Changed By')->placeholder('Employee / System'),
                            Infolists\Components\TextEntry::make('scheduled_for')->dateTime()->placeholder('—'),
                            Infolists\Components\TextEntry::make('public_note')->label('Public Note')->placeholder('—')->columnSpan(2),
                            Infolists\Components\TextEntry::make('internal_note')->label('Internal Note')->placeholder('—')->columnSpan(2),
                        ])->columns(4),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'apparatus', 'station', 'requestedByEmployee', 'createdBy', 'assignedTo',
            'updates.changedByUser',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ApparatusServiceTicket::class) ?? false;
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApparatusServiceTickets::route('/'),
            'view' => Pages\ViewApparatusServiceTicket::route('/{record}'),
        ];
    }
}
