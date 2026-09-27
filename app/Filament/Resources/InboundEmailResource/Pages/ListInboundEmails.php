<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Resources\InboundEmailResource;
use Filament\Resources\Pages\ListRecords;

final class ListInboundEmails extends ListRecords
{
    protected static string $resource = InboundEmailResource::class;

    public function getSubheading(): string
    {
        return 'Incoming messages must be under 3 MB in total. Keep attachments under 2 MB combined; long messages reduce the available space.';
    }
}
