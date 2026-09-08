<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Workgroup\WorkgroupMembershipService;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

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

    protected function handleRecordCreation(array $data): Model
    {
        $workgroup = Workgroup::query()->findOrFail($data['workgroup_id']);
        $userId = (int) $data['user_id'];

        app(WorkgroupMembershipService::class)->addUsers(
            $workgroup,
            [$userId],
            (string) ($data['role'] ?? 'member'),
            (bool) ($data['count_evaluations'] ?? true),
        );

        return WorkgroupMember::query()
            ->where('workgroup_id', $workgroup->id)
            ->where('user_id', $userId)
            ->sole();
    }
}
