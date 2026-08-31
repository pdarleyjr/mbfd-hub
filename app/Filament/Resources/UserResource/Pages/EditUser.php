<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Security\RoleAssignmentService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();
        $target = $this->record;

        if (! $actor instanceof User || ! $target instanceof User || ! array_key_exists('roles', $data)) {
            return $data;
        }

        $proposedRoleNames = Role::query()
            ->whereKey($data['roles'])
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $currentRoleNames = $target->getRoleNames()->sort()->values()->all();

        if ($proposedRoleNames !== $currentRoleNames) {
            app(RoleAssignmentService::class)->authorize($actor, $target, $proposedRoleNames);
        }

        return $data;
    }
}
