<?php

declare(strict_types=1);

namespace App\Filament\Resources\ResourceDocumentResource\Pages;

use App\Filament\Resources\ResourceDocumentResource;
use Filament\Resources\Pages\ListRecords;

final class ListResourceDocuments extends ListRecords
{
    protected static string $resource = ResourceDocumentResource::class;
}
