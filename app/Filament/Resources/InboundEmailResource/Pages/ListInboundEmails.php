<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Resources\InboundEmailResource;
use Filament\Resources\Pages\ListRecords;

final class ListInboundEmails extends ListRecords
{
    protected static string $resource = InboundEmailResource::class;
}
