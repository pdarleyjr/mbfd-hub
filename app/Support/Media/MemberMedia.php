<?php

declare(strict_types=1);

namespace App\Support\Media;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos and signatures members submit (Daily Checkout findings, concerns and stretcher
 * reports, inspector signatures, station requests, station inspections, TRT trailer counts).
 *
 * They are written to the private disk and are never web-reachable. Every reader goes
 * through this class, which looks on the private disk first and then on the public disk,
 * so a file that `photos:make-private` has not moved yet keeps working. The stored
 * relative path is the same on both disks, so no database row changes when a file moves.
 *
 * A page that needs a plain image address (Filament tables and infolists) gets a
 * short-lived signed URL bound to the signed-in viewer; the page issuing it has already
 * authorized the record. Member apps use their own per-record routes and stream here.
 */
final class MemberMedia
{
    public const PUBLIC_DISK = 'public';

    /** Top-level directories (on either disk) that hold member-submitted images. */
    public const DIRECTORIES = ['defects', 'signatures', 'station-requests', 'station-inspections', 'trt-inventory'];

    /** @var array<string, string> */
    public const IMAGE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public const ROUTE = 'member-media.show';

    /** A signed URL is valid for at least this long. */
    public const SIGNED_URL_MINUTES = 10;

    /** Expiry is rounded up to this many seconds, so one page shows the same address (browser cache) for a while. */
    private const SIGNED_URL_BUCKET_SECONDS = 300;

    private const CACHE_CONTROL = 'private, max-age=300';

    /** Where member images are written. Never the public disk, whatever the environment says. */
    public static function disk(): string
    {
        $disk = config('filesystems.private', 'local');

        return is_string($disk) && $disk !== '' && $disk !== self::PUBLIC_DISK ? $disk : 'local';
    }

    /** A stored relative path inside a member image directory, or null for anything else. */
    public static function safePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $path = trim($path);
        if ($path === '' || strlen($path) > 512 || str_contains($path, '..') || str_contains($path, '//')
            || preg_match('#^[A-Za-z0-9._/-]+$#', $path) !== 1) {
            return null;
        }
        $top = explode('/', $path, 2)[0];

        return in_array($top, self::DIRECTORIES, true) && str_contains($path, '/') ? $path : null;
    }

    public static function isImagePath(string $path): bool
    {
        return isset(self::IMAGE_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
    }

    /**
     * The disk that holds this file now: private first, then public.
     *
     * @return array{disk: string, path: string}|null
     */
    public static function locate(mixed $path): ?array
    {
        $safe = self::safePath($path);
        if ($safe === null) {
            return null;
        }
        foreach ([self::disk(), self::PUBLIC_DISK] as $disk) {
            if (Storage::disk($disk)->exists($safe)) {
                return ['disk' => $disk, 'path' => $safe];
            }
        }

        return null;
    }

    public static function exists(mixed $path): bool
    {
        return self::locate($path) !== null;
    }

    public static function get(mixed $path): ?string
    {
        $found = self::locate($path);
        $bytes = $found === null ? null : Storage::disk($found['disk'])->get($found['path']);

        return is_string($bytes) ? $bytes : null;
    }

    public static function size(mixed $path): ?int
    {
        $found = self::locate($path);

        return $found === null ? null : (int) Storage::disk($found['disk'])->size($found['path']);
    }

    /** Removes the file from both disks (a rolled-back submission or an abandoned upload). */
    public static function delete(mixed $path): void
    {
        $safe = self::safePath($path);
        if ($safe === null) {
            return;
        }
        foreach ([self::disk(), self::PUBLIC_DISK] as $disk) {
            Storage::disk($disk)->delete($safe);
        }
    }

    /** The private disk itself, for writers. */
    public static function privateStorage(): Filesystem
    {
        return Storage::disk(self::disk());
    }

    /**
     * Streams one image from whichever disk holds it. The caller has authorized the record;
     * a path is never taken from the request without that record. 404 when it is missing
     * or not an image.
     */
    public static function response(mixed $path, string $cacheControl = self::CACHE_CONTROL): StreamedResponse
    {
        $found = self::locate($path);
        abort_if($found === null || ! self::isImagePath($found['path']), 404);
        $extension = strtolower(pathinfo($found['path'], PATHINFO_EXTENSION));

        return Storage::disk($found['disk'])->response($found['path'], basename($found['path']), [
            'Content-Type' => self::IMAGE_TYPES[$extension],
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * A short-lived signed address for one image, valid only for the viewer it was issued to.
     * Null without a signed-in viewer or for a path outside the member image directories.
     */
    public static function url(mixed $path, ?Authenticatable $viewer = null): ?string
    {
        $safe = self::safePath($path);
        $viewer ??= auth()->user();
        if ($safe === null || $viewer === null || ! self::isImagePath($safe)) {
            return null;
        }
        $bucket = self::SIGNED_URL_BUCKET_SECONDS;
        $expires = (int) (ceil((now()->getTimestamp() + self::SIGNED_URL_MINUTES * 60) / $bucket) * $bucket);

        return url(URL::temporarySignedRoute(
            self::ROUTE,
            CarbonImmutable::createFromTimestamp($expires),
            ['path' => $safe, 'viewer' => (string) $viewer->getAuthIdentifier()],
            absolute: false,
        ));
    }
}
