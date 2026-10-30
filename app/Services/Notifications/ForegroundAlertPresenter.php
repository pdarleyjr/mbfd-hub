<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * The short, plain-text view of a member's in-app alerts that the page-side
 * foreground alerts (resources/js/pwa/foreground-alerts.ts) read to show a
 * pop-up while the Hub is open.
 *
 * It describes what is already in the member's bell; it never marks anything
 * read and never adds anything. The same shape is used by the Filament bell
 * (the snapshot rides on the bell's own Livewire poll) and by the member alert
 * feed for pages that have no bell.
 */
final class ForegroundAlertPresenter
{
    /** Only the newest alerts matter for a pop-up. */
    public const SNAPSHOT_SIZE = 20;

    public const TITLE_LIMIT = 120;

    public const BODY_LIMIT = 160;

    /**
     * A snapshot of the newest alerts on the bell's FIRST page, or null for any
     * other page (an older page must never look like new alerts).
     *
     * @param  iterable<DatabaseNotification>|Paginator  $notifications  Newest first.
     * @return array{now: int, alerts: list<array<string, mixed>>}|null
     */
    public function snapshotForPage(iterable|Paginator $notifications): ?array
    {
        if ($notifications instanceof Paginator && $notifications->currentPage() !== 1) {
            return null;
        }

        $items = [];
        foreach ($notifications as $notification) {
            if (count($items) >= self::SNAPSHOT_SIZE) {
                break;
            }
            $items[] = $notification;
        }

        return $this->snapshot($items);
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications  Newest first.
     * @return array{now: int, alerts: list<array<string, mixed>>}
     */
    public function snapshot(iterable $notifications): array
    {
        $registry = app(NotificationCategoryRegistry::class);
        $alerts = [];
        foreach ($notifications as $notification) {
            $alerts[] = $this->present($notification, $registry);
        }

        return ['now' => (int) now()->getTimestampMs(), 'alerts' => $alerts];
    }

    /**
     * @return array{id: string, title: string, body: string, url: ?string, urgent: bool, unread: bool, created: int}
     */
    public function present(DatabaseNotification $notification, ?NotificationCategoryRegistry $registry = null): array
    {
        $data = $notification->data;
        $title = self::plain($data['title'] ?? null, self::TITLE_LIMIT);

        return [
            'id' => (string) $notification->getKey(),
            'title' => $title !== '' ? $title : 'New alert',
            'body' => self::plain($data['body'] ?? null, self::BODY_LIMIT),
            'url' => $this->destination($data),
            'urgent' => $this->isUrgent($data, $registry ?? app(NotificationCategoryRegistry::class)),
            'unread' => $notification->read_at === null,
            'created' => (int) ($notification->created_at?->getTimestampMs() ?? 0),
        ];
    }

    /** Plain, single-line text: no markup, no control characters, trimmed to a length. */
    public static function plain(mixed $value, int $limit): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $limit, '…');
    }

    /**
     * The first same-origin link the alert offers (its action button, else a top-level url),
     * as a path. Anything that would leave the Hub is dropped rather than shown.
     *
     * @param  array<string, mixed>  $data
     */
    private function destination(array $data): ?string
    {
        $candidates = [];
        foreach (is_array($data['actions'] ?? null) ? $data['actions'] : [] as $action) {
            if (is_array($action) && is_string($action['url'] ?? null)) {
                $candidates[] = $action['url'];
            }
        }
        if (is_string($data['url'] ?? null)) {
            $candidates[] = $data['url'];
        }

        foreach ($candidates as $candidate) {
            $path = self::sameOriginPath($candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /** A path on this Hub ("/x?y#z") for a relative or same-host URL, else null. */
    public static function sameOriginPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            // "//host/x" is another site written without a scheme.
            return str_starts_with($url, '//') ? null : $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (! in_array(self::origin($parts), self::hubOrigins(), true)) {
            return null;
        }

        return ($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /** @param  array<string, mixed>  $parts */
    private static function origin(array $parts): string
    {
        $scheme = strtolower((string) $parts['scheme']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.strtolower((string) $parts['host']).($port !== null && $port !== $default ? ':'.$port : '');
    }

    /** @return list<string> */
    private static function hubOrigins(): array
    {
        $origins = [];
        foreach ([config('app.url'), app()->bound('request') ? request()->getSchemeAndHttpHost() : null] as $base) {
            $parts = is_string($base) && $base !== '' ? parse_url($base) : null;
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = self::origin($parts);
            }
        }

        return $origins;
    }

    /**
     * Urgent only when the alert says so, or its category is the registry's
     * "critical" significance. Everything else is announced politely.
     *
     * @param  array<string, mixed>  $data
     */
    private function isUrgent(array $data, NotificationCategoryRegistry $registry): bool
    {
        if (($data['urgent'] ?? false) === true) {
            return true;
        }
        $category = is_array($data['provenance'] ?? null) ? ($data['provenance']['category'] ?? null) : null;

        return is_string($category) && $registry->get($category)?->significance === 'critical';
    }
}
