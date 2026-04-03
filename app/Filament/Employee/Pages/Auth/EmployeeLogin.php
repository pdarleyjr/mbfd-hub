<?php

namespace App\Filament\Employee\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

/**
 * Custom Employee Portal login page.
 * Authenticates against the 'employee' guard using employee_id.
 */
class EmployeeLogin extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Employee ID')
            ->placeholder('Enter your Employee ID (e.g., 20731)')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    public function getCredentialsFromFormData(array $data): array
    {
        return [
            'employee_id' => $data['email'],
            'password'    => $data['password'],
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (ThrottleRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $credentials = $this->getCredentialsFromFormData($data);

        // Explicitly use the 'employee' guard
        if (! auth('employee')->attempt($credentials, $data['remember'] ?? false)) {
            $this->throwFailureValidationException();
        }

        session()->regenerate();

        // Redirect to the employee dashboard
        $this->redirect(route('filament.employee.pages.dashboard'), navigate: false);

        return app(LoginResponse::class);
    }
}
