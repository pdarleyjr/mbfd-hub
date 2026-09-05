<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundEmailResource\Pages;

use App\Filament\Resources\OutboundEmailResource;
use Filament\Resources\Pages\ListRecords;

final class ListOutboundEmails extends ListRecords
{
    protected static string $resource = OutboundEmailResource::class;
}
