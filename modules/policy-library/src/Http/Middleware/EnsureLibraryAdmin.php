<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mbfd\PolicyLibrary\Support\LibraryAccess;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLibraryAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->getHost() === config('policy-library.domain'), 404);
        if (! $request->user('web')) {
            $request->session()->put('url.intended', '/manage');

            return $request->expectsJson() || $request->headers->has('X-Livewire')
                ? response()->json(['message' => 'Your session has ended. Please sign in again.', 'code' => 'auth_session_expired'], 401)
                : redirect('/login');
        }
        abort_unless(LibraryAccess::canManage($request->user('web')), 403);

        return $next($request);
    }
}
