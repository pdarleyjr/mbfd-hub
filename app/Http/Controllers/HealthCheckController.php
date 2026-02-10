<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'baserow' => $this->checkBaserow(),
        ];

        $healthy = collect($checks)->every(fn ($c) => $c['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'ok', 'latency_ms' => $this->measure(fn () => DB::select('SELECT 1'))];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'error' => 'Connection failed'];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . uniqid();
            Cache::put($key, true, 5);
            $result = Cache::get($key);
            Cache::forget($key);
            return ['status' => $result ? 'ok' : 'fail'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'error' => 'Cache unavailable'];
        }
    }

    private function checkBaserow(): array
    {
        try {
            $response = Http::timeout(3)->get('http://127.0.0.1:8082/api/_health/');
            return ['status' => $response->successful() ? 'ok' : 'fail'];
        } catch (\Throwable $e) {
            return ['status' => 'unreachable'];
        }
    }

    private function measure(callable $fn): float
    {
        $start = microtime(true);
        $fn();
        return round((microtime(true) - $start) * 1000, 2);
    }
}
