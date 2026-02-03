<?php

namespace App\Filament\Resources\SingleGasMeterResource\Pages;

use App\Filament\Resources\SingleGasMeterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSingleGasMeter extends EditRecord
{
    protected static string $resource = SingleGasMeterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
