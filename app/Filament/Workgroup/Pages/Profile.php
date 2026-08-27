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
                ->form([
                    \Filament\Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $this->updateProfile($data);
                })
                ->modalSubmitActionLabel('Save'),
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

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(WorkgroupAccess::class)->canEnterPanel($user);
    }
}
