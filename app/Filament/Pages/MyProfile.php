<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rules\Password;

class MyProfile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user';

    protected static string $view = 'filament.pages.my-profile';

    protected static ?string $navigationLabel = 'My Profile';

    protected static ?int $navigationSort = 100;

    public ?array $data = [];
    public ?array $passwordData = [];

    public function mount(): void
    {
        $user = Auth::user();
        
        $this->form->fill([
            'display_name' => $user->display_name,
            'rank' => $user->rank,
            'station' => $user->station,
            'phone' => $user->phone,
        ]);

        // Show warning if password change is required
        if ($user->must_change_password) {
            Notification::make()
                ->warning()
                ->title('Password Change Required')
                ->body('You must change your password before continuing to use the system.')
                ->persistent()
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Profile Information')
                    ->schema([
                        Forms\Components\TextInput::make('display_name')
                            ->label('Display Name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('rank')
                            ->label('Rank')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('station')
                            ->label('Station')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Change Password')
                    ->schema([
                        Forms\Components\TextInput::make('current_password')
                            ->label('Current Password')
                            ->password()
                            ->required()
                            ->currentPassword()
                            ->revealable(),
                        Forms\Components\TextInput::make('password')
                            ->label('New Password')
                            ->password()
                            ->required()
                            ->confirmed()
                            ->rule(Password::default())
                            ->revealable(),
                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirm New Password')
                            ->password()
                            ->required()
                            ->revealable(),
                    ])
                    ->columns(2)
                    ->visible(fn () => Auth::user()->must_change_password)
                    ->collapsible(!Auth::user()->must_change_password),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Changes')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();

        // Separate password data from profile data
        $passwordData = [
            'current_password' => $data['current_password'] ?? null,
            'password' => $data['password'] ?? null,
            'password_confirmation' => $data['password_confirmation'] ?? null,
        ];

        unset($data['current_password'], $data['password'], $data['password_confirmation']);

        // Update profile data
        $user->update($data);

        // Update password if provided
        if (!empty($passwordData['password'])) {
            $user->update([
                'password' => Hash::make($passwordData['password']),
                'must_change_password' => false, // Clear the flag
            ]);

            Notification::make()
                ->success()
                ->title('Password changed successfully')
                ->body('Your password has been updated.')
                ->send();
        }

        Notification::make()
            ->success()
            ->title('Profile updated successfully')
            ->send();
            
        // Refresh the page to remove the warning
        if ($user->must_change_password === false) {
            redirect()->route('filament.admin.pages.dashboard');
        }
    }
}
