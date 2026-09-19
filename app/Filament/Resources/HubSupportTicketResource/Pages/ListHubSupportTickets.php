<?php

declare(strict_types=1);

namespace App\Filament\Resources\HubSupportTicketResource\Pages;

use App\Filament\Resources\HubSupportTicketResource;
use Filament\Resources\Pages\ListRecords;

final class ListHubSupportTickets extends ListRecords
{
    protected static string $resource = HubSupportTicketResource::class;
}
