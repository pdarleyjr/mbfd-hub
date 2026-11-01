<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Host\HubHosts;
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
            // An installed window cannot leave its host without leaving the app.
            || HubHosts::isInstalledSiblingRequest($request)
        ) {
            return $next($request);
        }

        $scheme = parse_url($canonicalUrl, PHP_URL_SCHEME);
        $port = parse_url($canonicalUrl, PHP_URL_PORT);
        $origin = (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.$canonicalHost
            .(is_int($port) ? ':'.$port : '');
        $target = $origin.$request->getRequestUri();

        // A window opened straight into one of these pages (a /daily/ install, a shortcut, a notification) has not
        // run the Hub head yet, so the cookie cannot be there. Let the page decide before anything leaves this host.
        if ($this->isDocumentNavigation($request) && strcasecmp($requestHost, (string) HubHosts::siblingHost()) === 0) {
            return response()->view('pwa.installed-host-check', ['target' => $target], 200, ['Cache-Control' => 'no-store']);
        }

        return redirect()->to($target, 308);
    }

    /** Fetch metadata says a person is opening this page. Anything without it keeps the plain redirect. */
    private function isDocumentNavigation(Request $request): bool
    {
        return $request->isMethod('GET')
            && $request->headers->get('Sec-Fetch-Mode') === 'navigate'
            && $request->headers->get('Sec-Fetch-Dest') === 'document';
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}
