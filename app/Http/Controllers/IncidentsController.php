<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class IncidentsController extends Controller
{
    private const CACHE_KEY = 'pulsepoint_incidents';

    private const CACHE_TTL = 60; // seconds

    private const LAST_GOOD_KEY = 'pulsepoint_incidents_last_good';

    private const LAST_GOOD_TTL = 21_600; // six hours

    private const FAILURE_COUNT_KEY = 'pulsepoint_incidents_failures';

    private const CIRCUIT_KEY = 'pulsepoint_incidents_circuit_until';

    private const CIRCUIT_THRESHOLD = 3;

    private const CIRCUIT_SECONDS = 120;

    /**
     * Public endpoint — proxies the PulsePoint CF Worker with server-side
     * caching so the Worker (and PulsePoint) are only hit once per minute.
     */
    public function index(): JsonResponse
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $this->success($cached, false, 'pulsepoint-cache');
        }

        try {
            if ((int) Cache::get(self::CIRCUIT_KEY, 0) > now()->timestamp) {
                throw new RuntimeException('circuit_open');
            }

            $data = Cache::lock('pulsepoint_incidents_fetch_lock', 15)->block(2, function (): array {
                $cached = Cache::get(self::CACHE_KEY);
                if (is_array($cached)) {
                    return $cached;
                }

                $response = $this->requestWorker();
                $decoded = $response->json();
                if (! is_array($decoded)
                    || ! is_array($decoded['active'] ?? null)
                    || ! is_array($decoded['recent'] ?? null)) {
                    throw new RuntimeException('invalid_response_shape');
                }

                $previousFailures = (int) Cache::pull(self::FAILURE_COUNT_KEY, 0);
                Cache::forget(self::CIRCUIT_KEY);
                Cache::put(self::CACHE_KEY, $decoded, self::CACHE_TTL);
                Cache::put(self::LAST_GOOD_KEY, [
                    'data' => $decoded,
                    'stored_at' => now()->toISOString(),
                ], self::LAST_GOOD_TTL);

                if ($previousFailures > 0) {
                    Log::info('PulsePoint incident feed recovered.', [
                        'previous_failures' => $previousFailures,
                    ]);
                }

                return $decoded;
            });

            return $this->success($data, false, 'pulsepoint-proxy');
        } catch (Throwable $exception) {
            return $this->degraded($exception);
        }
    }

    private function requestWorker(): Response
    {
        $url = (string) config('services.pulsepoint.worker_url');
        if ($url === '') {
            throw new RuntimeException('worker_not_configured');
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::connectTimeout(3)
                    ->timeout(10)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'User-Agent' => 'MBFDHub/1.0',
                    ])
                    ->get($url);
            } catch (ConnectionException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }

                usleep(250_000);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $retryable = $response->status() === 429 || $response->serverError();
            if (! $retryable || $attempt === 2) {
                throw new RuntimeException('worker_http_'.$response->status());
            }

            usleep(250_000);
        }

        throw new RuntimeException('worker_retry_exhausted');
    }

    private function degraded(Throwable $exception): JsonResponse
    {
        $failures = Cache::increment(self::FAILURE_COUNT_KEY);
        Cache::put('pulsepoint_incidents_last_failure_at', now()->toISOString(), self::LAST_GOOD_TTL);
        if ($failures >= self::CIRCUIT_THRESHOLD) {
            Cache::put(self::CIRCUIT_KEY, now()->addSeconds(self::CIRCUIT_SECONDS)->timestamp, self::CIRCUIT_SECONDS);
        }

        if (Cache::add('pulsepoint_incidents_failure_alert', true, 300)) {
            Log::warning('PulsePoint fetch failed.', [
                'failure_code' => $this->failureCode($exception),
                'consecutive_failures' => $failures,
                'circuit_open' => $failures >= self::CIRCUIT_THRESHOLD,
            ]);
        }

        $lastGood = Cache::get(self::LAST_GOOD_KEY);
        if (is_array($lastGood) && is_array($lastGood['data'] ?? null)) {
            $data = $lastGood['data'];
            $data['stale'] = true;
            $data['staleAsOf'] = $lastGood['stored_at'] ?? null;

            return $this->success($data, true, 'pulsepoint-last-known-good');
        }

        return response()->json([
            'error' => 'Incident feed unavailable',
            'active' => [],
            'recent' => [],
            'stale' => false,
            'fetchedAt' => now()->toISOString(),
        ], 503)->header('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $data */
    private function success(array $data, bool $stale, string $source): JsonResponse
    {
        $data['stale'] = $stale;

        return response()->json($data)
            ->header('Cache-Control', $stale ? 'private, max-age=15' : 'private, max-age=60')
            ->header('X-Data-Source', $source)
            ->header('X-Data-Stale', $stale ? 'true' : 'false');
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'connection_failed';
        }

        $allowed = ['circuit_open', 'worker_not_configured', 'invalid_response_shape', 'worker_retry_exhausted'];
        if (in_array($exception->getMessage(), $allowed, true)) {
            return $exception->getMessage();
        }
        if (preg_match('/^worker_http_\d{3}$/', $exception->getMessage())) {
            return $exception->getMessage();
        }

        return 'unexpected_failure';
    }
}
