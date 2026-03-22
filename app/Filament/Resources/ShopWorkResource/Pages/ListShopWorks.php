<?php

namespace App\Filament\Resources\ShopWorkResource\Pages;

use App\Filament\Resources\ShopWorkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShopWorks extends ListRecords
{
    protected static string $resource = ShopWorkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
