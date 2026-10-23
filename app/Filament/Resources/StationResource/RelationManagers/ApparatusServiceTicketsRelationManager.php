<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Enums\ApparatusServiceTicketPriority;
use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Models\ApparatusServiceTicket;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Apparatus service / repair tickets filed for this station. Status changes are
 * made on the ticket itself so the workflow service records every transition.
 * Visibility follows the ApparatusServiceTicket policy (admin.fleet.view).
 */
class ApparatusServiceTicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'apparatusServiceTickets';

    protected static ?string $title = 'Apparatus Service';

    protected static ?string $recordTitleAttribute = 'ticket_number';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ticket_number')->label('Ticket')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit_designation_snapshot')->label('Unit')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('title')->limit(50)->searchable(),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'attention' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->label())
                    ->color(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->color()),
                Tables\Columns\TextColumn::make('created_at')->label('Filed')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('scheduled_for')->dateTime()->placeholder('Not scheduled')->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('open')
                    ->label('Open tickets')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('archived_at')->whereIn('status', ApparatusServiceTicketStatus::openValues()),
                        false: fn (Builder $query): Builder => $query->whereIn('status', ApparatusServiceTicketStatus::terminalValues()),
                    ),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ApparatusServiceTicketStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()),
                Tables\Filters\SelectFilter::make('priority')->options(ApparatusServiceTicketPriority::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No apparatus service tickets')
            ->emptyStateDescription('Service and repair requests filed for this station appear here.')
            ->actions([
                Tables\Actions\Action::make('open_ticket')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ApparatusServiceTicket $record): string => ApparatusServiceTicketResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
