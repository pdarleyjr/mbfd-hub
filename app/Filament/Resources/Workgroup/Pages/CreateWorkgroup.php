<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupResource;
use App\Models\Workgroup;
use App\Services\Workgroup\WorkgroupMembershipService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateWorkgroup extends CreateRecord
{
    protected static string $resource = WorkgroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $memberUserIds = $data['member_user_ids'] ?? [];
        unset($data['member_user_ids']);

        return DB::transaction(function () use ($data, $memberUserIds): Workgroup {
            $workgroup = Workgroup::query()->create($data);
            app(WorkgroupMembershipService::class)->addUsers($workgroup, $memberUserIds);

            return $workgroup;
        });
    }
}
