<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventPreviousUrlStorage
{
    /**
     * Keep background PWA asset requests out of Laravel's navigation history.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Purpose', 'prefetch');

        return $next($request);
    }
}
