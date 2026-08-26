<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class SetPasswordPage extends Page
{
    protected static ?string $slug = 'set-password';

    protected static string $view = 'filament.pages.set-password';

    protected static ?string $title = 'Set Your Password';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Password Change Required')
                    ->description('Set a new password before continuing to use this panel.')
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
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $hashedPassword = Hash::make($data['password']);

        // Hash before assignment so the existing bcrypt-aware cast does not
        // capture plaintext for the ScreenTinker observer.
        $user->update([
            'password' => $hashedPassword,
            'must_change_password' => false,
        ]);

        // AuthenticateSession compares this value on the next request.
        session()->put('password_hash_web', $hashedPassword);

        Notification::make()
            ->success()
            ->title('Password changed successfully')
            ->send();

        $this->redirect(Filament::getCurrentPanel()->getUrl() ?? url(Filament::getCurrentPanel()->getPath()));
    }
}
