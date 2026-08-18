<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Models\ApparatusServiceTicket;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceTicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceTickets';

    protected static ?string $title = 'Service / Maintenance History';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ticket_number')
            ->columns([
                Tables\Columns\TextColumn::make('ticket_number')->label('Ticket')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('title')->limit(55)->searchable(),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('priority')->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->label())
                    ->color(fn (string $state): string => ApparatusServiceTicketStatus::from($state)->color()),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('scheduled_for')->dateTime()->placeholder('Not scheduled')->sortable(),
                Tables\Columns\TextColumn::make('completed_at')->dateTime()->placeholder('Open')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('view_ticket')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ApparatusServiceTicket $record): string => ApparatusServiceTicketResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
