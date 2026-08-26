<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use App\Models\User;
use App\Models\Workgroup;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Resources\Pages\EditRecord;

class EditWorkgroupMember extends EditRecord
{
    protected static string $resource = WorkgroupMemberResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $workgroup = Workgroup::find($data['workgroup_id'] ?? null);

        abort_unless($user instanceof User && $workgroup !== null, 404);
        abort_unless(app(WorkgroupAccess::class)->canManageWorkgroup($user, $workgroup), 404);

        return $data;
    }
}
