<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use App\Models\User;
use App\Models\Workgroup;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkgroupMember extends CreateRecord
{
    protected static string $resource = WorkgroupMemberResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        $workgroup = Workgroup::find($data['workgroup_id'] ?? null);

        abort_unless($actor instanceof User && $workgroup !== null, 404);
        abort_unless(app(WorkgroupAccess::class)->canManageWorkgroup($actor, $workgroup), 404);

        return $data;
    }
}
