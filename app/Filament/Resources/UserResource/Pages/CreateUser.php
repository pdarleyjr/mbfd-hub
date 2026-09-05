<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Services\Identity\CanonicalCityEmailService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): User {
            $cityEmail = $data['city_email'] ?? null;
            unset($data['city_email']);
            $employee = Employee::query()->firstOrCreate(
                ['employee_id' => $data['employee_id']],
                ['name' => $data['name'], 'rank' => $data['rank'] ?? null, 'roster_status' => 'active'],
            );
            $data['employee_profile_id'] = $employee->getKey();
            $data['must_change_password'] = true;

            $user = User::query()->create($data);
            if (filled($cityEmail)) {
                app(CanonicalCityEmailService::class)->sync($employee, $user, (string) $cityEmail);
            }

            return $user;
        });
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;
        UserNotificationSubscription::ensureDepartmentUpdatesForUser($user->id);
        foreach (array_keys(User::notificationPreferenceDefinitions()) as $eventKey) {
            $user->notificationSubscriptions()->firstOrCreate(
                ['event_key' => $eventKey],
                ['database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false],
            );
        }
    }
}
