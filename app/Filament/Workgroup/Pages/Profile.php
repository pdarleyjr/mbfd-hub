<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\WorkgroupMember;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
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
        
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['workgroup'])
            ->first();
    }

    protected function updateProfile(array $data): void
    {
        $user = Auth::user();
        
        if (!$user) {
            return;
        }

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);
    }
}
