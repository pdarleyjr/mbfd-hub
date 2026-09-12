<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalHostRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonicalUrl = rtrim((string) config('app.url'), '/');
        $canonicalHost = parse_url($canonicalUrl, PHP_URL_HOST);
        $requestHost = $request->getHost();

        if (
            ! is_string($canonicalHost)
            || $canonicalHost === ''
            || strcasecmp($requestHost, $canonicalHost) === 0
            || $this->isLocalHost($canonicalHost)
            || $this->isLocalHost($requestHost)
        ) {
            return $next($request);
        }

        $scheme = parse_url($canonicalUrl, PHP_URL_SCHEME);
        $origin = is_string($scheme) && $scheme !== ''
            ? $scheme.'://'.$canonicalHost
            : 'https://'.$canonicalHost;

        return redirect()->to($origin.$request->getRequestUri(), 308);
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}
