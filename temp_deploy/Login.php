<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class Login extends BaseLogin
{
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

    public function authenticate(): ?\Filament\Http\Responses\Auth\Contracts\LoginResponse
    {
        try {
            $this->rateLimit(100);
        } catch (\Illuminate\Http\Exceptions\ThrottleRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();
            return null;
        }

        $data = $this->form->getState();
        
        // Find user with case-insensitive email lookup
        $email = strtolower($data['email']);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        
        if (!$user) {
            Log::warning("Login failed - user not found: {$email}");
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            ]);
        }
        
        Log::info("Login attempt for: {$user->email}, pw length: " . strlen($data['password']));
        
        // Authenticate using the exact email from database
        if (!Auth::attempt(['email' => $user->email, 'password' => $data['password']], $data['remember'] ?? false)) {
            Log::warning("Login failed - bad password for: {$user->email}");
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            ]);
        }

        Log::info("Login success for: {$user->email}");
        session()->regenerate();

        return app(\Filament\Http\Responses\Auth\Contracts\LoginResponse::class);
    }
}
