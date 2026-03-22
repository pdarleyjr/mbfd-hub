<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use Filament\Resources\Pages\EditRecord;

class EditWorkgroupMember extends EditRecord
{
    protected static string $resource = WorkgroupMemberResource::class;
}
