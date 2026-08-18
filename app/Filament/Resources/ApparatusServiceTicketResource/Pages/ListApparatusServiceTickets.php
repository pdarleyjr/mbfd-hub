<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusServiceTicketResource\Pages;

use App\Enums\ApparatusServiceTicketStatus;
use App\Filament\Resources\ApparatusServiceTicketResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListApparatusServiceTickets extends ListRecords
{
    protected static string $resource = ApparatusServiceTicketResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ApparatusServiceTicketStatus::openValues())),
            'needs_review' => Tab::make('Needs Review')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusServiceTicketStatus::Submitted->value)),
            'urgent' => Tab::make('Urgent')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ApparatusServiceTicketStatus::openValues())->where('priority', 'urgent')),
            'scheduled' => Tab::make('Scheduled')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusServiceTicketStatus::Scheduled->value)),
            'in_progress' => Tab::make('In Progress')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusServiceTicketStatus::InProgress->value)),
            'waiting_for_parts' => Tab::make('Waiting for Parts')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusServiceTicketStatus::WaitingForParts->value)),
            'completed' => Tab::make('Completed')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusServiceTicketStatus::Completed->value)),
            'cancelled' => Tab::make('Cancelled')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusServiceTicketStatus::Cancelled->value)),
            'all' => Tab::make('All'),
        ];
    }
}
