<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkgroupMember extends CreateRecord
{
    protected static string $resource = WorkgroupMemberResource::class;
}
