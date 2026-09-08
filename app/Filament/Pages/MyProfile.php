<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\AccountProfileResource;
use App\Filament\Resources\EmployeeResource;
use App\Models\User;
use Filament\Pages\Page;

class MyProfile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user';

    protected static string $view = 'filament.employees.legacy-redirect';

    protected static ?string $navigationLabel = 'My Profile';

    protected static ?int $navigationSort = 100;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isAuthenticationAllowed(), 403);
        if ($user->must_change_password) {
            $this->redirect(SetPasswordPage::getUrl(panel: 'admin'));

            return;
        }
        if ($user->employee_profile_id !== null) {
            $employee = $user->employeeProfile;
            abort_unless($employee !== null && $employee->employee_id === $user->employee_id, 409);
            $this->redirect(EmployeeResource::getUrl('edit', ['record' => $employee]));

            return;
        }
        $this->redirect(AccountProfileResource::getUrl('edit', ['record' => $user]));
    }
}
