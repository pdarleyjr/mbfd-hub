<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ViewerGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $grant = $request->session()->get('policy-library.access');
        if (! is_array($grant) || ($grant['user_id'] ?? null) !== (string) $request->user('web')?->getAuthIdentifier()
            || ($grant['expires_at'] ?? 0) <= now()->timestamp
            || ($grant['pin_version'] ?? null) !== hash('sha256', (string) config('policy-library.pin_hash'))) {
            $request->session()->forget('policy-library.access');
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Enter the library access PIN to continue.', 'redirect' => '/access'], 403);
            }
            $request->session()->put('policy-library.intended', $request->getRequestUri());

            return redirect()->route('policy-library.access');
        }

        return $next($request);
    }
}
