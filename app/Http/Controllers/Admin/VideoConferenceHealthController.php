<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VideoConferenceHealthController extends Controller
{
    public function __invoke(ConferenceProvider $provider): JsonResponse
    {
        if (! config('video-conferencing.enabled')) {
            return response()->json(['status' => 'disabled']);
        }

        $healthy = $provider->healthCheck();

        return response()->json(['status' => $healthy ? 'healthy' : 'unavailable'], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
