<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Resources\Pages\ListRecords;
use Mbfd\PolicyLibrary\Filament\Resources\ImportResource;

final class ListImports extends ListRecords
{
    protected static string $resource = ImportResource::class;
}
