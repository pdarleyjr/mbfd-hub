<?php

declare(strict_types=1);

namespace App\Support\Host;

use Illuminate\Http\Request;

/**
 * The Hub answers on two hosts: the canonical www host and its apex sibling. An installed PWA belongs to the
 * host it was installed from and that cannot change, so a window installed on the sibling must stay there:
 * crossing hosts leaves the app's scope and drops the member into Safari or Chrome.
 *
 * Session and XSRF cookies are scoped to the parent domain, so both hosts share a sign-in.
 */
final class HubHosts
{
    /**
     * Set by the Hub head (components/hub/head.blade.php, Daily index.html) in an installed window on the
     * sibling host. Host-only and unsigned: it decides nothing but which of two equally authorized hosts
     * answers, so forging it only keeps the sender on the host they are already on.
     */
    public const INSTALLED_COOKIE = 'mbfd_installed_host';

    public static function canonicalHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** The www host's apex, or the apex's www host. Null for a host with no sibling (localhost, an address). */
    public static function siblingHost(): ?string
    {
        $canonical = self::canonicalHost();
        if ($canonical === null || filter_var($canonical, FILTER_VALIDATE_IP) !== false || ! str_contains($canonical, '.')) {
            return null;
        }

        return str_starts_with($canonical, 'www.') ? substr($canonical, 4) : 'www.'.$canonical;
    }

    public static function isHubHost(string $host): bool
    {
        $host = strtolower($host);

        return $host !== '' && ($host === self::canonicalHost() || $host === self::siblingHost());
    }

    /** True for a request from a window installed on the sibling host. Everything else is canonical behavior. */
    public static function isInstalledSiblingRequest(Request $request): bool
    {
        return strcasecmp($request->getHost(), (string) self::siblingHost()) === 0
            && $request->cookies->get(self::INSTALLED_COOKIE) === '1';
    }

    public static function canonicalOrigin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** The origin a page should treat as home: its own when it is served by a Hub host, else the configured one. */
    public static function originFor(Request $request): string
    {
        return self::isHubHost($request->getHost()) ? $request->getSchemeAndHttpHost() : self::canonicalOrigin();
    }

    public static function siblingOrigin(): ?string
    {
        $sibling = self::siblingHost();
        if ($sibling === null) {
            return null;
        }
        $parts = parse_url(self::canonicalOrigin());
        $port = $parts['port'] ?? null;

        return ($parts['scheme'] ?? 'https').'://'.$sibling.($port !== null ? ':'.$port : '');
    }
}
