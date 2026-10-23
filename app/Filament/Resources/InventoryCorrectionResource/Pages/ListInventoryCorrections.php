<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCorrectionResource\Pages;

use App\Filament\Resources\InventoryCorrectionResource;
use Filament\Resources\Pages\ListRecords;

class ListInventoryCorrections extends ListRecords
{
    protected static string $resource = InventoryCorrectionResource::class;

    public function getSubheading(): ?string
    {
        return 'Checklist entries members reported as wrong. Approving one changes the Daily Checkout checklist for the apparatus you choose.';
    }
}
