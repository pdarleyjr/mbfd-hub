<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Models\Apparatus;
use App\Models\ApparatusServiceTicket;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ServiceTicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceTickets';

    protected static ?string $title = 'Service Tickets';

    /** Badge shows tickets still open, matching the Overview tab count. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus ? $ownerRecord->serviceTickets()->open()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->active())
            ->recordTitleAttribute('ticket_number')
            ->recordUrl(fn (ApparatusServiceTicket $record): ?string => self::ticketUrl($record))
            ->columns([
                // The title wraps under the ticket number instead of taking a column; category and the
                // schedule dates are one click away in the column toggle, so View is never clipped.
                Tables\Columns\TextColumn::make('ticket_number')
                    ->label('Ticket')
                    ->searchable(['ticket_number', 'title'])
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (ApparatusServiceTicket $record): string => (string) $record->title)
                    ->wrap()
                    ->visibleFrom('md'),
                // Phones: ticket, status and title in one column so View stays on screen.
                Tables\Columns\TextColumn::make('ticket_phone')
                    ->label('Ticket')
                    ->getStateUsing(fn (ApparatusServiceTicket $record): string => (string) $record->ticket_number)
                    ->weight('semibold')
                    ->description(fn (ApparatusServiceTicket $record): string => ApparatusServiceTicketStatus::from($record->status)->label().' · '.$record->title)
                    ->wrap()
                    ->hiddenFrom('md'),
                Tables\Columns\TextColumn::make('priority')->badge()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->label())
                    ->color(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->color())
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('created_at')->label('Created')->date('M j, Y')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('category')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('scheduled_for')->dateTime()->placeholder('Not scheduled')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('completed_at')->dateTime()->placeholder('Open')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('view_ticket')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (ApparatusServiceTicket $record): bool => self::ticketUrl($record) !== null)
                    ->url(fn (ApparatusServiceTicket $record): ?string => self::ticketUrl($record)),
            ]);
    }

    /** Only administrators who may open the ticket get a link to it. */
    private static function ticketUrl(ApparatusServiceTicket $record): ?string
    {
        return auth()->user()?->can('view', $record)
            ? ApparatusServiceTicketResource::getUrl('view', ['record' => $record])
            : null;
    }
}
