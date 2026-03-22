<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogLivewireErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->isLivewireRequest($request) && $response->getStatusCode() >= 500) {
            $context = [
                'user_id' => $request->user()?->id,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'component' => $request->input('components.0.snapshot.memo.name')
                    ?? $request->input('fingerprint.name')
                    ?? 'unknown',
                'fingerprint' => $request->input('components.0.snapshot.memo.id')
                    ?? $request->input('fingerprint.id')
                    ?? null,
                'status_code' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toIso8601String(),
            ];

            Log::error('Livewire 500 error detected', $context);

            // Add Sentry breadcrumb if available
            if (function_exists('app') && app()->bound('sentry')) {
                \Sentry\addBreadcrumb(
                    category: 'livewire',
                    message: 'Livewire update failed with 500',
                    metadata: $context,
                    level: \Sentry\Severity::error(),
                );
            }
        }

        return $response;
    }

    private function isLivewireRequest(Request $request): bool
    {
        return $request->is('livewire/*')
            || $request->header('X-Livewire') === 'true';
    }
}
