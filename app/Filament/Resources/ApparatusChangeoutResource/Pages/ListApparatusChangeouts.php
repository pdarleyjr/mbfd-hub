<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusChangeoutResource\Pages;

use App\Filament\Resources\ApparatusChangeoutResource;
use Filament\Resources\Pages\ListRecords;

class ListApparatusChangeouts extends ListRecords
{
    protected static string $resource = ApparatusChangeoutResource::class;

    public function getTitle(): string
    {
        return 'Vehicle changeouts';
    }
}
