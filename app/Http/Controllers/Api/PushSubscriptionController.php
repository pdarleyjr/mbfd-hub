<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\PushDeviceLabel;
use App\Services\Notifications\PushSessionBinding;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\PushSubscription;

class PushSubscriptionController extends Controller
{
    private const P256DH_LENGTH = 65;

    private const AUTH_SECRET_LENGTH = 16;

    /**
     * Store a new push subscription for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => [
                'bail',
                'required',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->isAllowedEndpoint($value)) {
                        $fail('The :attribute must be an HTTPS URL for a configured push provider.');
                    }
                },
            ],
            'label' => ['nullable', 'string', 'max:80'],
            // RFC 8291 aes128gcm is what Apple requires and every current browser supports.
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            'keys.p256dh' => [
                'bail',
                'required',
                'string',
                'max:128',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->isValidP256dh($value)) {
                        $fail('The :attribute must be a valid Web Push P-256 key.');
                    }
                },
            ],
            'keys.auth' => [
                'bail',
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->isValidAuthSecret($value)) {
                        $fail('The :attribute must be a valid Web Push authentication secret.');
                    }
                },
            ],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info('Push subscription store requested', [
            'user_id' => $user->id,
            'endpoint_hash' => $this->endpointHash($validated['endpoint']),
            'p256dh_length' => strlen($validated['keys']['p256dh']),
            'auth_length' => strlen($validated['keys']['auth']),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        try {
            $subscription = $this->saveSubscription(
                $user,
                $validated['endpoint'],
                $validated['keys']['p256dh'],
                $validated['keys']['auth'],
                (string) $request->userAgent(),
                is_string($validated['label'] ?? null) ? $validated['label'] : null,
                is_string($validated['content_encoding'] ?? null) ? $validated['content_encoding'] : 'aes128gcm',
                PushSessionBinding::forRequest($request),
            );

            if ($subscription === null) {
                return $this->endpointOwnershipConflict();
            }

            $subscriptionCount = $user->pushSubscriptions()->count();

            Log::info('Push subscription saved successfully', [
                'user_id' => $user->id,
                'endpoint_hash' => $this->endpointHash($validated['endpoint']),
                'subscription_count' => $subscriptionCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Push subscription saved successfully',
                'subscriptionCount' => $subscriptionCount,
            ]);
        } catch (QueryException $exception) {
            if ($this->endpointIsOwnedByAnotherUser($user, $validated['endpoint'])) {
                return $this->endpointOwnershipConflict();
            }

            Log::error('Push subscription save failed', [
                'user_id' => $user->id,
                'endpoint_hash' => $this->endpointHash($validated['endpoint']),
                'exception' => $exception,
            ]);

            return $this->subscriptionSaveFailed();
        } catch (\Throwable $exception) {
            Log::error('Push subscription save failed', [
                'user_id' => $user->id,
                'endpoint_hash' => $this->endpointHash($validated['endpoint']),
                'exception' => $exception,
            ]);

            return $this->subscriptionSaveFailed();
        }
    }

    /**
     * Delete a push subscription for the authenticated user.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info('Push subscription delete requested', [
            'user_id' => $user->id,
            'endpoint_hash' => $this->endpointHash($validated['endpoint']),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        try {
            $user->deletePushSubscription($validated['endpoint']);

            $subscriptionCount = $user->pushSubscriptions()->count();

            Log::info('Push subscription removed successfully', [
                'user_id' => $user->id,
                'endpoint_hash' => $this->endpointHash($validated['endpoint']),
                'subscription_count' => $subscriptionCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Push subscription removed successfully',
                'subscriptionCount' => $subscriptionCount,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Push subscription delete failed', [
                'user_id' => $user->id,
                'endpoint_hash' => $this->endpointHash($validated['endpoint']),
                'exception' => $exception,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove push subscription',
            ], 500);
        }
    }

    /**
     * Get VAPID public key for client-side subscription.
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => config('webpush.vapid.public_key'),
        ]);
    }

    /**
     * An endpoint already registered to another member moves to this member only when the
     * request proves possession of the same subscription keys (a shared station browser
     * signing in as someone else); any other claim on it is refused.
     */
    private function saveSubscription(User $user, string $endpoint, string $publicKey, string $authToken, string $userAgent, ?string $labelHint, string $contentEncoding, ?string $sessionBinding = null): ?PushSubscription
    {
        $deviceMeta = [
            'content_encoding' => $contentEncoding,
            'session_binding' => $sessionBinding,
            'user_agent' => $userAgent === '' ? null : mb_substr($userAgent, 0, 255),
            'device_label' => PushDeviceLabel::fromUserAgent($userAgent, $labelHint),
        ];

        return DB::transaction(function () use ($user, $endpoint, $publicKey, $authToken, $deviceMeta): ?PushSubscription {
            /** @var PushSubscription|null $subscription */
            $subscription = app(config('webpush.model'))
                ->newQuery()
                ->where('endpoint', $endpoint)
                ->lockForUpdate()
                ->first();

            if ($subscription !== null && ! $user->ownsPushSubscription($subscription)) {
                if (! $this->provesPossession($subscription, $publicKey, $authToken)) {
                    return null;
                }

                Log::notice('Push subscription moved to the member who proved possession of its keys', [
                    'previous_subscribable_id' => $subscription->subscribable_id,
                    'user_id' => $user->id,
                    'endpoint_hash' => $this->endpointHash($endpoint),
                ]);
                $subscription->subscribable_id = $user->getKey();
                $subscription->subscribable_type = $user->getMorphClass();
            }

            if ($subscription !== null) {
                $subscription->forceFill([
                    'public_key' => $publicKey,
                    'auth_token' => $authToken,
                    ...$deviceMeta,
                    'failure_count' => 0,
                    'last_failure_reason' => null,
                    'backoff_until' => null,
                ])->save();

                return $subscription;
            }

            /** @var PushSubscription $createdSubscription */
            $createdSubscription = $user->pushSubscriptions()->create([
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                ...$deviceMeta,
            ]);

            return $createdSubscription;
        });
    }

    private function provesPossession(PushSubscription $subscription, string $publicKey, string $authToken): bool
    {
        return is_string($subscription->public_key) && is_string($subscription->auth_token)
            && $subscription->public_key !== '' && $subscription->auth_token !== ''
            && hash_equals($subscription->public_key, $publicKey)
            && hash_equals($subscription->auth_token, $authToken);
    }

    private function endpointIsOwnedByAnotherUser(User $user, string $endpoint): bool
    {
        /** @var PushSubscription|null $subscription */
        $subscription = app(config('webpush.model'))->findByEndpoint($endpoint);

        return $subscription !== null && ! $user->ownsPushSubscription($subscription);
    }

    private function endpointOwnershipConflict(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This push subscription is already registered to another user.',
        ], 409);
    }

    private function subscriptionSaveFailed(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Failed to save push subscription.',
        ], 500);
    }

    private function isAllowedEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint) || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($endpoint);

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        $host = $this->normalizeHost($parts['host']);

        return $host !== null && $this->hostIsAllowed($host);
    }

    /**
     * Exact match, or `*.base`, which matches one or more whole labels before the base:
     * `wns2-ch1p.notify.windows.com` yes; `notify.windows.com.evil.test`,
     * `evilnotify.windows.com` and the bare base itself no.
     */
    private function hostIsAllowed(string $host): bool
    {
        foreach ($this->allowedEndpointHosts() as $allowed) {
            if (! str_starts_with($allowed, '*.')) {
                if ($host === $allowed) {
                    return true;
                }

                continue;
            }

            // "*.base" => ".base": the dot keeps the match on a label boundary.
            if (str_ends_with($host, substr($allowed, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedEndpointHosts(): array
    {
        $hosts = config('webpush.allowed_endpoint_hosts', []);

        if (! is_array($hosts)) {
            return [];
        }

        $normalizedHosts = [];

        foreach ($hosts as $host) {
            $normalizedHost = $this->normalizeAllowlistEntry($host);

            if ($normalizedHost !== null) {
                $normalizedHosts[] = $normalizedHost;
            }
        }

        return array_values(array_unique($normalizedHosts));
    }

    /**
     * Allow-list entries are exact hosts or `*.base`. A wildcard base needs at least three
     * labels (`notify.windows.com`), so `*.com` or `*.googleapis.com` cannot be configured.
     */
    private function normalizeAllowlistEntry(mixed $entry): ?string
    {
        if (! is_string($entry) || ! str_starts_with(trim($entry), '*.')) {
            return $this->normalizeHost($entry);
        }

        $base = $this->normalizeHost(substr(trim($entry), 2));

        return $base !== null && substr_count($base, '.') >= 2 ? '*.'.$base : null;
    }

    private function normalizeHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === ''
            || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $host;
    }

    private function isValidP256dh(string $publicKey): bool
    {
        $decodedKey = $this->decodeBase64Url($publicKey);

        return $decodedKey !== null
            && strlen($decodedKey) === self::P256DH_LENGTH
            && ord($decodedKey[0]) === 4;
    }

    private function isValidAuthSecret(string $authSecret): bool
    {
        $decodedSecret = $this->decodeBase64Url($authSecret);

        return $decodedSecret !== null && strlen($decodedSecret) === self::AUTH_SECRET_LENGTH;
    }

    private function decodeBase64Url(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }

        $paddingLength = (4 - (strlen($value) % 4)) % 4;
        $decodedValue = base64_decode(strtr($value.str_repeat('=', $paddingLength), '-_', '+/'), true);

        return $decodedValue === false ? null : $decodedValue;
    }

    private function endpointHash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
