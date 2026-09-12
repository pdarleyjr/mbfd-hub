<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\SurveyResource;
use App\Models\User;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Resources\Pages\CreateRecord;

class CreateSurvey extends CreateRecord
{
    protected static string $resource = SurveyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 404);
        $workgroup = app(WorkgroupContext::class)->requireCurrent($user);
        abort_unless(app(\App\Support\Workgroups\WorkgroupAccess::class)->canManageWorkgroup($user, $workgroup), 404);
        $data['workgroup_id'] = $workgroup->id;
        $data['created_by'] = $user->id;

        return $data;
    }
}
