<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Mbfd\PolicyLibrary\Filament\Resources\ManualResource;

final class CreateManual extends CreateRecord
{
    protected static string $resource = ManualResource::class;
}
