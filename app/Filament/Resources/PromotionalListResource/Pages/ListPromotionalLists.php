<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalListResource\Pages;

use App\Filament\Resources\PromotionalListResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPromotionalLists extends ListRecords
{
    protected static string $resource = PromotionalListResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New list')];
    }
}
