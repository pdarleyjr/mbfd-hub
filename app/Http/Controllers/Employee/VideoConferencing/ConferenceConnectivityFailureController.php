<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceClientFailureMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConferenceConnectivityFailureController extends Controller
{
    public function __invoke(Request $request, ConferenceClientFailureMonitor $monitor): Response
    {
        $validated = $request->validate([
            'stage' => ['required', Rule::in(['preflight', 'signaling', 'media_publication'])],
            'room' => ['required', Rule::in(['lineup', 'direct'])],
            'join_as' => ['required', Rule::in(['self', '300', 'sta1', 'sta2', 'sta3', 'sta4', 'sta6'])],
            'failure_code' => ['required', 'string', 'max:64'],
            'session_id' => ['nullable', 'string', 'max:26'],
        ]);
        $employee = $request->user('employee');
        $clientIp = (string) $request->ip();

        Log::warning('Video conference client connection failed', [
            'employee_id' => $employee?->getAuthIdentifier(),
            'stage' => $validated['stage'],
            'room' => $validated['room'],
            'join_as' => $validated['join_as'],
            'failure_code' => $validated['failure_code'],
            'session_id' => $validated['session_id'] ?? null,
            'client_ip_hash' => hash_hmac('sha256', $clientIp, (string) config('app.key')),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);
        $monitor->record();

        return response()->noContent();
    }
}
