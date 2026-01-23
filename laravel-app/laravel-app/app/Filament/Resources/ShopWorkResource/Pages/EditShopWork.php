<?php

namespace App\Filament\Resources\ShopWorkResource\Pages;

use App\Filament\Resources\ShopWorkResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShopWork extends EditRecord
{
    protected static string $resource = ShopWorkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
