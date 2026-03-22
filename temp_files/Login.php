<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();
            return null;
        }

        $data = $this->form->getState();
        
        // Case-insensitive email lookup
        $email = strtolower($data['email']);
        $user = \App\Models\User::whereRaw('LOWER(email) = ?', [$email])->first();
        
        if ($user && Auth::attempt(['email' => $user->email, 'password' => $data['password']], $data['remember'] ?? false)) {
            session()->regenerate();
            return app(LoginResponse::class);
        }

        $this->throwFailureValidationException();
    }
    
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
