<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupMember;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Profile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static string $view = 'filament-workgroup.pages.profile';

    protected static ?string $title = 'Profile';

    protected static ?string $navigationLabel = 'Profile';

    public ?string $name = '';

    public ?string $email = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user?->name;
        $this->email = $user?->email;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editProfile')
                ->label('Edit Profile')
                ->icon('heroicon-o-pencil')
                ->color('primary')
                ->fillForm(function (): array {
                    $user = Auth::user();

                    return $user instanceof User ? ['display_name' => $user->display_name, 'phone' => $user->phone] : [];
                })
                ->form([
                    \Filament\Forms\Components\TextInput::make('display_name')
                        ->label('Display name')
                        ->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $this->updateProfile($data);
                })
                ->modalSubmitActionLabel('Save'),
            Action::make('cityEmail')
                ->label('City email & verification')
                ->icon('heroicon-o-envelope')
                ->url(fn (): string => route('city-email.show'))
                ->visible(fn (): bool => Auth::user()?->employee_profile_id !== null),
        ];
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return app(WorkgroupContext::class)->member($user)?->load('workgroup');
    }

    protected function updateProfile(array $data): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);
        if ($user->employee_profile_id !== null) {
            $employee = $user->employeeProfile;
            abort_unless($employee !== null, 409);
            app(\App\Services\Identity\EmployeeProfileService::class)->update($user, $employee, $data);
        } else {
            app(\App\Services\Security\EmployeeAccountAdministration::class)->updateUnlinkedProfile($user, $user, $data);
        }
        \Filament\Notifications\Notification::make()->success()->title('Profile saved')->send();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(WorkgroupAccess::class)->canEnterPanel($user);
    }
}
