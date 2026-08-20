<?php

namespace App\Services\VideoConferencing;

use App\Models\Employee;
use Illuminate\Http\Request;

class ConferenceCommandAuthorizationService
{
    private const SESSION_KEY = 'video_conferencing.command_authorization';

    public function __construct(private readonly ConferenceCommandPinService $pin) {}

    public function authorize(Request $request, Employee $employee, ?string $pin): void
    {
        $this->pin->verify($employee, (string) $request->ip(), $pin);
        $request->session()->put(self::SESSION_KEY, [
            'employee_id' => $employee->getKey(),
            'expires_at' => now()->addMinutes(20)->getTimestamp(),
        ]);
    }

    public function assertAuthorized(Request $request, Employee $employee): void
    {
        $authorization = $request->session()->get(self::SESSION_KEY);
        abort_unless(
            is_array($authorization)
                && (string) ($authorization['employee_id'] ?? '') === (string) $employee->getKey()
                && (int) ($authorization['expires_at'] ?? 0) >= now()->getTimestamp(),
            403,
            'Enter the 300 command PIN to continue.',
        );
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
