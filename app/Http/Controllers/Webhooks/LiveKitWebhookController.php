<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LiveKitWebhookController extends Controller
{
    public function __invoke(Request $request, ConferenceWebhookService $webhooks): Response
    {
        try {
            $webhooks->handle($request->getContent(), $request->header('Authorization'));
        } catch (ConferenceUnavailableException) {
            abort(401, 'Invalid LiveKit webhook signature.');
        }

        return response()->noContent();
    }
}
