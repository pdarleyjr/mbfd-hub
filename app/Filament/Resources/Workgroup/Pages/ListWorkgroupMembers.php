<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkgroupMembers extends ListRecords
{
    protected static string $resource = WorkgroupMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
