<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\CandidateProductResource;
use App\Models\User;
use App\Models\WorkgroupSession;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Resources\Pages\CreateRecord;

class CreateCandidateProduct extends CreateRecord
{
    protected static string $resource = CandidateProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $session = WorkgroupSession::find($data['workgroup_session_id'] ?? null);

        abort_unless($user instanceof User && $session !== null, 404);
        app(WorkgroupAccess::class)->requireManageSession($user, $session);

        return $data;
    }
}
