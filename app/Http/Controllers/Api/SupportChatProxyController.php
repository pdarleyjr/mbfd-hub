<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SupportChatProxyController extends Controller
{
    public function chat(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
            'stream' => ['nullable', 'boolean'],
        ]);

        $workerUrl = rtrim((string) config('cloudflare.worker_url'), '/');
        $secret = (string) config('cloudflare.worker_api_secret');

        if ($workerUrl === '' || $secret === '') {
            return response()->json(['error' => 'Support chat is not configured.'], 503);
        }

        $payload = [
            'message' => $validated['message'],
            'history' => $validated['history'] ?? [],
            'stream' => (bool) ($validated['stream'] ?? false),
        ];

        $response = Http::withHeaders([
            'x-api-secret' => $secret,
            'Accept' => $payload['stream'] ? 'text/event-stream' : 'application/json',
        ])
            ->timeout(120)
            ->withOptions(['stream' => $payload['stream']])
            ->post("{$workerUrl}/chat", $payload);

        if (! $payload['stream']) {
            return response()->json(
                $response->json() ?? ['error' => 'Support chat unavailable.'],
                $response->status()
            );
        }

        if (! $response->successful()) {
            return response()->json(
                $response->json() ?? ['error' => 'Support chat unavailable.'],
                $response->status()
            );
        }

        return response()->stream(function () use ($response): void {
            $body = $response->toPsrResponse()->getBody();

            while (! $body->eof()) {
                echo $body->read(8192);
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Sources' => $response->header('X-Sources') ?? '[]',
        ]);
    }
}
