<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Navigation context only: this never authenticates a user or grants app access. */
final class FederationLoginAttempt
{
    private const PREFIX = 'hub_login_attempt_';

    public function requested(Request $request): bool
    {
        return $request->query->has('login_attempt');
    }

    public function begin(Request $request, string $destination): Response
    {
        $destination = app(CanonicalLoginDestination::class)->federation($destination);
        $count = count(array_filter(array_keys($request->cookies->all()),
            static fn (string $name): bool => str_starts_with($name, self::PREFIX)));
        if ($destination === null || $count >= 8) {
            return $this->unavailable();
        }
        // Issuance is anonymous: omitted cookies must not allow unbounded cache
        // allocation. This budget is separate from credentials and machine SSO.
        $budget = 'canonical-login-attempt-issuance:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        if (RateLimiter::hit($budget, 60) > 120) {
            $retryAfter = max(1, RateLimiter::availableIn($budget));

            return response()->view('auth.federation-restart', ['retryAfter' => $retryAfter], 429, [
                'Cache-Control' => 'no-store, private', 'Retry-After' => (string) $retryAfter,
            ]);
        }

        $id = bin2hex(random_bytes(32));
        $binding = bin2hex(random_bytes(32));
        Cache::put($this->key($id), [
            'destination' => $destination,
            'binding_hash' => hash('sha256', $binding),
            'expires_at' => now()->getTimestamp() + 300,
        ], 300);

        return (new RedirectResponse('/login?login_attempt='.$id, 302, ['Cache-Control' => 'no-store, private']))
            ->withCookie(Cookie::make(self::PREFIX.$id, $binding, 5, '/', null,
                $request->isSecure() || app()->environment('production'), true, false, 'lax')->withDomain(null));
    }

    public function current(Request $request): ?string
    {
        $id = $this->id($request);
        $binding = $id === null ? null : $request->cookie(self::PREFIX.$id);
        if ($id === null || ! is_string($binding) || preg_match('/\A[a-f0-9]{64}\z/', $binding) !== 1) {
            return null;
        }
        $attempt = Cache::get($this->key($id));
        if (! is_array($attempt) || ! is_string($attempt['binding_hash'] ?? null)
            || ! is_int($attempt['expires_at'] ?? null) || $attempt['expires_at'] <= now()->getTimestamp()
            || ! hash_equals($attempt['binding_hash'], hash('sha256', $binding))) {
            return null;
        }

        return app(CanonicalLoginDestination::class)->federation($attempt['destination'] ?? null);
    }

    public function loginUrl(Request $request, bool $expired = false): string
    {
        return '/login?login_attempt='.$this->id($request).($expired ? '&session_expired=1' : '');
    }

    public function applicationLabel(Request $request): string
    {
        return match (parse_url($this->current($request) ?? '', PHP_URL_PATH)) {
            '/auth/media-control/authorize' => 'Media Control',
            '/auth/bid/authorize' => 'MBFD Bid',
            default => 'your MBFD application',
        };
    }

    public function complete(Request $request): Response
    {
        $id = $this->id($request);
        if ($id === null) {
            return $this->unavailable();
        }
        try {
            // Redis/array cache locks serialize single consumption across workers.
            // A competing/replayed completion cannot re-use the same attempt.
            $destination = Cache::lock($this->key($id).':consume', 5)->block(1, function () use ($request, $id): ?string {
                $destination = $this->current($request);
                if ($destination !== null) {
                    Cache::forget($this->key($id));
                }

                return $destination;
            });
        } catch (LockTimeoutException) {
            return $this->unavailable();
        }

        if ($destination === null) {
            return $this->unavailable();
        }

        return (new RedirectResponse($destination, 302, ['Cache-Control' => 'no-store, private']))
            ->withCookie(Cookie::forget(self::PREFIX.$id, '/', null)->withDomain(null));
    }

    public function unavailable(): Response
    {
        return response()->view('auth.federation-restart', [], 409, ['Cache-Control' => 'no-store, private']);
    }

    private function id(Request $request): ?string
    {
        $id = $request->query('login_attempt');

        return is_string($id) && preg_match('/\A[a-f0-9]{64}\z/', $id) === 1 ? $id : null;
    }

    private function key(string $id): string
    {
        return 'canonical-federation-login:'.$id;
    }
}
