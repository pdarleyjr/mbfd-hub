<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Filament\Facades\Filament;
use Throwable;

/**
 * Makes sure a notification's click-through is same-origin and points somewhere
 * the recipient may open. Anything else becomes the recipient's landing page,
 * so a lock-screen tap never lands on a 403 or leaves the site.
 */
final class NotificationLink
{
    /** @var array<string, string> Panel path segment => panel id. */
    private const PANEL_SEGMENTS = [
        'admin' => 'admin',
        'training' => 'training',
        'workgroups' => 'workgroups',
        'employee' => 'employee',
    ];

    /**
     * @param  string  $url  A site-relative path, or an absolute URL on this site.
     * @return string The original URL when it is same-origin and enterable, otherwise the landing path.
     */
    public static function resolve(User $recipient, string $url): string
    {
        $path = self::sameOriginPath($url);
        if ($path === null) {
            return self::landing($recipient);
        }

        $pathOnly = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
        $segment = strtolower(explode('/', ltrim($pathOnly, '/'))[0]);
        $panelId = self::PANEL_SEGMENTS[$segment] ?? null;
        if ($panelId !== null && ! self::canEnter($recipient, $panelId)) {
            return self::landing($recipient);
        }

        return $url;
    }

    /** The relative path (with query and fragment) when the URL stays on this site, else null. */
    public static function sameOriginPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1f\\\\]/', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parts = parse_url($url);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || ! is_string($appHost) || strtolower($parts['host']) !== strtolower($appHost)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return ($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public static function landing(User $recipient): string
    {
        return self::canEnter($recipient, 'employee') ? '/employee' : '/account';
    }

    private static function canEnter(User $recipient, string $panelId): bool
    {
        try {
            return $recipient->canAccessPanel(Filament::getPanel($panelId));
        } catch (Throwable) {
            return false;
        }
    }
}
