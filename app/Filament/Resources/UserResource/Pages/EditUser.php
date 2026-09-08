<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\AccountProfileResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\Page;

class EditUser extends Page
{
    protected static string $resource = UserResource::class;

    protected static string $view = 'filament.employees.legacy-redirect';

    public function mount(int|string $record): void
    {
        abort_unless(UserResource::canViewAny(), 403);
        $account = User::query()->with('employeeProfile')->findOrFail($record);
        if ($account->employee_profile_id !== null) {
            $employee = $account->employeeProfile;
            abort_unless($employee !== null && $employee->employee_id === $account->employee_id, 409, 'The employee identity mapping requires review. No redirect was guessed.');
            $this->redirect(EmployeeResource::getUrl('edit', ['record' => $employee]));

            return;
        }
        $this->redirect(AccountProfileResource::getUrl('edit', ['record' => $account]));
    }
}
