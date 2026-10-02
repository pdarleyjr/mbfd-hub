<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Mbfd\PolicyLibrary\Filament\Resources\EditionResource;

final class CreateEdition extends CreateRecord
{
    protected static string $resource = EditionResource::class;
}
