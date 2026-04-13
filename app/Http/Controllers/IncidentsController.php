<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IncidentsController extends Controller
{
    private const WORKER_URL = 'https://pulsepoint-proxy.pdarleyjr.workers.dev/incidents';
    private const CACHE_KEY  = 'pulsepoint_incidents';
    private const CACHE_TTL  = 60; // seconds

    /**
     * Public endpoint — proxies the PulsePoint CF Worker with server-side
     * caching so the Worker (and PulsePoint) are only hit once per minute.
     */
    public function index(): JsonResponse
    {
        try {
            $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'User-Agent' => 'MBFDHub/1.0',
                    ])
                    ->get(self::WORKER_URL);

                if ($response->failed()) {
                    throw new \RuntimeException(
                        "Worker responded {$response->status()}"
                    );
                }

                $decoded = $response->json();
                if (! is_array($decoded)) {
                    throw new \RuntimeException('Worker returned non-array body');
                }
                return $decoded;
            });

            return response()->json($data)
                ->header('Cache-Control', 'private, max-age=60')
                ->header('X-Data-Source', 'pulsepoint-proxy');

        } catch (\Throwable $e) {
            Log::warning('PulsePoint fetch failed', ['error' => $e->getMessage()]);

            return response()->json([
                'error'   => 'Incident feed unavailable',
                'active'  => [],
                'recent'  => [],
                'fetchedAt' => now()->toISOString(),
            ], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
