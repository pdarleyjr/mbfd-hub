<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\User;
use App\Services\Identity\CanonicalUserResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class TrainingRosterSubmissionAuthenticator
{
    public function __construct(private readonly CanonicalUserResolver $users) {}

    /** Verify a single submission without authenticating the kiosk's session. */
    public function authenticate(string $email, #[SensitiveParameter] string $password, string $ip): User
    {
        $email = trim($email);
        $key = 'canonical-login:'.hash_hmac('sha256', mb_strtolower($email).'|'.$ip, (string) config('app.key'));
        $maxAttempts = max(1, (int) config('security.canonical_login.max_attempts', 5));
        $decaySeconds = max(1, (int) config('security.canonical_login.decay_seconds', 60));

        if (! config('identity.local_login_enabled') || $email === '' || mb_strlen($email) > 254
            || $password === '' || strlen($password) > 1024 || RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw $this->denied();
        }

        $user = $this->users->byIdentifier($email);
        $provider = Auth::createUserProvider((string) config('auth.guards.web.provider'));
        $passwordMatches = false;
        if ($user !== null && $provider !== null) {
            $passwordMatches = $provider->validateCredentials($user, ['password' => $password]);
        } else {
            Hash::check($password, (string) config('identity.canonical_login_dummy_password_hash'));
        }

        if ($user === null || ! $passwordMatches || ! $user->canSubmitTrainingRosters()) {
            RateLimiter::hit($key, $decaySeconds);
            throw $this->denied();
        }

        RateLimiter::clear($key);

        return $user;
    }

    private function denied(): ValidationException
    {
        return ValidationException::withMessages(['credentials' => 'The provided credentials cannot authorize this submission.']);
    }
}
