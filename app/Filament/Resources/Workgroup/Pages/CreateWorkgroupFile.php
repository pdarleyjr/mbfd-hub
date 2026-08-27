<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupFileResource;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupSession;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkgroupFile extends CreateRecord
{
    protected static string $resource = WorkgroupFileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->assertAssociationIsManaged($data);

        return $data;
    }

    private function assertAssociationIsManaged(array $data): void
    {
        $user = auth()->user();
        $workgroup = Workgroup::find($data['workgroup_id'] ?? null);

        abort_unless($user instanceof User && $workgroup !== null, 404);
        $access = app(WorkgroupAccess::class);
        abort_unless($access->canManageWorkgroup($user, $workgroup), 404);

        if (! empty($data['workgroup_session_id'])) {
            $session = WorkgroupSession::find($data['workgroup_session_id']);
            abort_unless($session !== null && $session->workgroup_id === $workgroup->id, 404);
            $access->requireManageSession($user, $session);
        }
    }
}
