<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BaserowWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.baserow.webhook_secret');

        if (! $secret || $request->header('X-Baserow-Webhook-Secret') !== $secret) {
            Log::warning('Baserow webhook: invalid secret');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $eventType = $request->input('event_type', 'unknown');
        $tableId = $request->input('table_id');
        $rowId = $request->input('row_id');

        Log::info('Baserow webhook received', [
            'event_type' => $eventType,
            'table_id' => $tableId,
            'row_id' => $rowId,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
