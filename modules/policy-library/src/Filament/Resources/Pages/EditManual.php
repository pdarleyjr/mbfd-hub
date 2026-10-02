<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord;
use Mbfd\PolicyLibrary\Filament\Resources\ManualResource;

final class EditManual extends EditRecord
{
    protected static string $resource = ManualResource::class;
}
