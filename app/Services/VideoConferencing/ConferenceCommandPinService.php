<?php

namespace App\Services\VideoConferencing;

use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ConferenceCommandPinService
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function verify(Employee $employee, string $ipAddress, ?string $pin): void
    {
        $hash = (string) config('video-conferencing.command_pin_hash');
        if ($hash === '') {
            throw new ConferenceUnavailableException('300 command access is not configured. Contact an administrator.');
        }

        $keys = [
            'conference-command-pin:employee:'.$employee->getKey(),
            'conference-command-pin:ip:'.hash('sha256', $ipAddress),
        ];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw new TooManyRequestsHttpException(
                    RateLimiter::availableIn($key),
                    'Too many incorrect 300 command PIN attempts. Try again shortly.',
                );
            }
        }

        if ($pin === null || ! Hash::check($pin, $hash)) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            throw ValidationException::withMessages([
                'command_pin' => 'The 300 command PIN is incorrect.',
            ]);
        }

        foreach ($keys as $key) {
            RateLimiter::clear($key);
        }
    }
}
