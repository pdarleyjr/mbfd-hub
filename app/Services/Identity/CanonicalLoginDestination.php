<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use Filament\Facades\Filament;

final class CanonicalLoginDestination
{
    public const PASSWORD_RETURN_KEY = 'auth.password_return_to';

    private const FEDERATION_PATHS = ['/auth/bid/authorize', '/auth/media-control/authorize'];

    public function federation(mixed $candidate): ?string
    {
        $destination = $this->normalizeInternalPath($candidate);

        return $destination !== null && in_array(parse_url($destination, PHP_URL_PATH), self::FEDERATION_PATHS, true)
            ? $destination : null;
    }

    public function resolve(User $user, mixed $candidate): string
    {
        $destination = $this->normalizeInternalPath($candidate);

        if ($destination === null) {
            return '/';
        }

        $path = parse_url($destination, PHP_URL_PATH);

        if (in_array($path, self::FEDERATION_PATHS, true)) {
            return $destination;
        }

        if ($path === '/account/city-email' || preg_match('#\A/account/city-email/verify/[a-f0-9]{64}\z#', (string) $path) === 1) {
            return $destination;
        }

        foreach ([
            'admin' => '/admin',
            'employee' => '/employee',
            'training' => '/training',
            'workgroups' => '/workgroups',
        ] as $panelId => $prefix) {
            if (! $this->hasPathPrefix($destination, $prefix)) {
                continue;
            }

            return $user->canAccessPanel(Filament::getPanel($panelId)) ? $destination : '/';
        }

        return '/';
    }

    private function normalizeInternalPath(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || strlen($candidate) > 8192) {
            return null;
        }

        $decoded = $candidate;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        if (str_contains($decoded, '\\') || str_starts_with($decoded, '//') || preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
            return null;
        }

        $parts = parse_url($decoded);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $application = parse_url((string) config('app.url'));
            $sameHost = strcasecmp($parts['host'], $application['host'] ?? '') === 0;
            $samePort = ($parts['port'] ?? null) === ($application['port'] ?? null);

            if (! $sameHost || ! $samePort || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
                return null;
            }

            $decoded = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            $parts = parse_url($decoded);
        } elseif (isset($parts['scheme'])) {
            return null;
        }

        if (! is_array($parts) || ! str_starts_with($decoded, '/')) {
            return null;
        }

        $segments = explode('/', $parts['path'] ?? '');

        if (array_intersect($segments, ['.', '..']) !== []) {
            return null;
        }

        // Decode for safety checks only. Preserve the original query encoding:
        // decoding %2B to + would change an opaque SSO state or callback value.
        $original = parse_url($candidate);

        return ($parts['path'] ?? '/').(isset($original['query']) ? '?'.$original['query'] : '');
    }

    private function hasPathPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }
}
