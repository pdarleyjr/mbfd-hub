<?php

declare(strict_types=1);

namespace App\Support;

use Livewire\Livewire;

final class HubNavigation
{
    public static function safePath(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $candidate)
            || preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($candidate))) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['host']) || isset($parts['scheme'])) {
            $origin = parse_url(request()->getSchemeAndHttpHost());
            if (! isset($parts['scheme'], $parts['host'])
                || strtolower($parts['scheme']) !== strtolower($origin['scheme'])
                || strtolower($parts['host']) !== strtolower($origin['host'])
                || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== ($origin['port'] ?? ($origin['scheme'] === 'https' ? 443 : 80))) {
                return null;
            }
        }

        $path = $parts['path'] ?? '/';
        // Hub UI paths do not need encoded separators or dot segments. Reject
        // ambiguous paths instead of letting browsers and the server disagree.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($candidate, '//')
            || str_contains($path, '%') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)
            || ! preg_match('#^/(?:$|(?:admin|employee|training|workgroups|daily|updates|account|security-standards)(?:/|$)|support/issues(?:/|$))#', $path)
            || preg_match('#/(?:login|logout|auth|reset-password|forgot-password)(?:/|$)#', $path)) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public static function backUrl(?string $parent = null): string
    {
        $current = request()->fullUrl();
        $candidate = request()->query('return_to');
        if (Livewire::isLivewireRequest()) {
            $current = Livewire::originalUrl();
            $referrer = self::safePath(request()->headers->get('referer'));
            if ($referrer !== null && parse_url($referrer, PHP_URL_PATH) === parse_url($current, PHP_URL_PATH)) {
                parse_str(parse_url($referrer, PHP_URL_QUERY) ?? '', $query);
                $candidate = $query['return_to'] ?? null;
            }
        }

        $currentPath = parse_url($current, PHP_URL_PATH);
        $explicit = self::safePath($candidate);
        if ($explicit !== null && parse_url($explicit, PHP_URL_PATH) !== $currentPath) {
            return $explicit;
        }

        $parent = self::safePath($parent);
        $previous = request()->hasSession() ? self::safePath(request()->session()->previousUrl()) : null;
        if ($parent !== null && parse_url($parent, PHP_URL_PATH) !== $currentPath) {
            // Restore the actual parent list's query, including pagination, when
            // Laravel's session history confirms that exact parent path.
            return $previous !== null && parse_url($previous, PHP_URL_PATH) === parse_url($parent, PHP_URL_PATH)
                ? $previous : $parent;
        }

        return $previous !== null && parse_url($previous, PHP_URL_PATH) !== $currentPath ? $previous : '/';
    }
}
