<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Resources\Pages\EditRecord;

class EditWorkgroupMember extends EditRecord
{
    protected static string $resource = WorkgroupMemberResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $record = $this->getRecord();
        $workgroup = $record instanceof WorkgroupMember ? $record->workgroup : null;

        abort_unless($user instanceof User && $workgroup instanceof Workgroup, 404);
        abort_unless(app(WorkgroupAccess::class)->canManageWorkgroup($user, $workgroup), 404);

        unset($data['user_id'], $data['workgroup_id']);

        return $data;
    }
}
