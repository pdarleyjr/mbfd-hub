<?php

namespace App\Filament\Resources\SingleGasMeterResource\Pages;

use App\Filament\Resources\SingleGasMeterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSingleGasMeters extends ListRecords
{
    protected static string $resource = SingleGasMeterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
