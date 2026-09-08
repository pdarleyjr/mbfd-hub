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

                    return $user instanceof User ? ['name' => $user->name, 'email' => $user->email] : [];
                })
                ->form([
                    \Filament\Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->disabled(fn (): bool => Auth::user()?->employee_profile_id !== null)
                        ->dehydrated(fn (): bool => Auth::user()?->employee_profile_id === null)
                        ->helperText('Linked members manage their city email through City email & verification.')
                        ->required()
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

        if (! $user) {
            return;
        }

        $changes = ['name' => $data['name']];
        if ($user->employee_profile_id === null) {
            $changes['email'] = $data['email'];
        }

        $user->update($changes);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(WorkgroupAccess::class)->canEnterPanel($user);
    }
}
