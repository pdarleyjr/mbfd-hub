<?php

namespace App\Filament\Employee\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\Rules\Password;

class ChangePasswordPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';
    protected static string $view = 'filament.employee.pages.change-password';
    protected static ?string $title = 'Set Your Password';
    protected static ?int $navigationSort = 99;
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
                TextInput::make('password')
                    ->label('New Password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::min(8)->mixedCase()->numbers())
                    ->same('password_confirmation'),
                TextInput::make('password_confirmation')
                    ->label('Confirm New Password')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        /** @var \App\Models\Employee $employee */
        $employee = auth('employee')->user();
        $hashedPassword = \Illuminate\Support\Facades\Hash::make($data['password']);

        // Use direct DB update to bypass Eloquent's password hash comparison
        // which would trigger session invalidation
        \Illuminate\Support\Facades\DB::table('employees')
            ->where('id', $employee->id)
            ->update([
                'password'             => $hashedPassword,
                'must_change_password' => false,
                'updated_at'           => now(),
            ]);

        // Update the in-memory model without triggering the session guard's hash check
        $employee->setRawAttributes(array_merge($employee->getAttributes(), [
            'password'             => $hashedPassword,
            'must_change_password' => false,
        ]));

        // Re-authenticate to refresh the session with the new password hash
        auth('employee')->setUser($employee);

        // CRITICAL: Update the session's stored password hash so Laravel doesn't
        // invalidate the session when it detects the password changed in DB.
        // Laravel stores the hash as 'password_hash_{guard_name}' in the session.
        session()->put('password_hash_employee', $hashedPassword);

        \Filament\Notifications\Notification::make()
            ->title('Password updated! Welcome to the Employee Portal.')
            ->success()
            ->send();

        $this->redirect(route('filament.employee.pages.dashboard'));
    }
}
