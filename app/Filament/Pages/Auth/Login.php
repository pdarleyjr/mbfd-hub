<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Filament\Http\Responses\Auth\LoginResponse;

class Login extends BaseLogin
{
    /**
     * Prevent "Intended URL" session poisoning from cross-panel navigation.
     * If a user visited /employee while unauthenticated, Laravel stores
     * /employee as url.intended. Without this flush, a successful admin
     * login would redirect back to /employee, causing a guard crash.
     */
    public function mount(): void
    {
        session()->forget('url.intended');
        parent::mount();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('email')
                    ->label(__('filament-panels::pages/auth/login.form.email.label'))
                    ->email()
                    ->required()
                    ->autocomplete()
                    ->autofocus()
                    ->extraInputAttributes(['tabindex' => 1]),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ])
            ->statePath('data');
    }

    public function authenticate(): ?LoginResponse
    {
        // Flush any cross-panel intended URL before authenticating
        session()->forget('url.intended');

        try {
            $this->rateLimit(5);
        } catch (\Illuminate\Http\Exceptions\ThrottleRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();
            return null;
        }

        $data = $this->form->getState();
        
        // Find user with case-insensitive email lookup
        $email = strtolower($data['email']);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        
        if (!$user) {
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            ]);
        }
        
        // Authenticate using the exact email from database
        if (!Auth::attempt(['email' => $user->email, 'password' => $data['password']], $data['remember'] ?? false)) {
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            ]);
        }

        session()->regenerate();

        return new LoginResponse();
    }
}
