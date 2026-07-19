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
    public static function safeIntendedPath(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || str_contains($candidate, '\\') || str_starts_with($candidate, '//')) {
            return null;
        }

        $decoded = $candidate;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
            return null;
        }

        $parts = parse_url($decoded);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $application = parse_url(config('app.url'));
            $sameHost = strcasecmp($parts['host'], $application['host'] ?? '') === 0;
            $samePort = ($parts['port'] ?? null) === ($application['port'] ?? null);
            if (! $sameHost || ! $samePort || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
                return null;
            }
            $decoded = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            $parts = parse_url($decoded);
        } elseif (isset($parts['scheme'])) {
            return null;
        }

        if (! str_starts_with($decoded, '/employee/')) {
            return null;
        }

        $segments = explode('/', $parts['path'] ?? '');
        if (array_intersect($segments, ['.', '..']) !== []) {
            return null;
        }

        return $decoded;
    }

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
            'password' => $data['password'],
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

        $intended = session()->pull('employee.intended_path') ?? session()->pull('url.intended');
        $target = self::safeIntendedPath($intended) ?? route('filament.employee.pages.dashboard');
        $this->redirect($target, navigate: false);

        return app(LoginResponse::class);
    }
}
