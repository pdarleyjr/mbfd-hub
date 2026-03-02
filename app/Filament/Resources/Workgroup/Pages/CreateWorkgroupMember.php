<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateWorkgroupMember extends CreateRecord
{
    protected static string $resource = WorkgroupMemberResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // If creating a new user, create the user first
        if (!empty($this->data['create_new_user']) && empty($data['user_id'])) {
            $user = User::create([
                'name' => $this->data['new_user_name'],
                'email' => $this->data['new_user_email'],
                'password' => Hash::make($this->data['new_user_password']),
            ]);

            // Assign workgroup member role
            if (method_exists($user, 'assignRole')) {
                $user->assignRole('workgroup_member');
            }

            $data['user_id'] = $user->id;
        }

        return $data;
    }
}
