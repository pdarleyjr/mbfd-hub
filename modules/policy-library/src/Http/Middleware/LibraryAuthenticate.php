<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LibraryAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('web')) {
            if ($request->expectsJson() || $request->headers->has('X-Livewire')) {
                return response()->json(['message' => 'Sign in to MBFD Hub to continue.', 'redirect' => '/login'], 401);
            }
            $request->session()->put('policy-library.intended', $request->getRequestUri());
            $request->session()->put('url.intended', '/access');

            return redirect('/login');
        }

        return $next($request);
    }
}
