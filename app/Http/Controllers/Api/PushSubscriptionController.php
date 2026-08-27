<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
                $validated['keys']['auth']
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

    private function saveSubscription(User $user, string $endpoint, string $publicKey, string $authToken): ?PushSubscription
    {
        return DB::transaction(function () use ($user, $endpoint, $publicKey, $authToken): ?PushSubscription {
            /** @var PushSubscription|null $subscription */
            $subscription = app(config('webpush.model'))
                ->newQuery()
                ->where('endpoint', $endpoint)
                ->lockForUpdate()
                ->first();

            if ($subscription !== null && ! $user->ownsPushSubscription($subscription)) {
                return null;
            }

            if ($subscription !== null) {
                $subscription->public_key = $publicKey;
                $subscription->auth_token = $authToken;
                $subscription->save();

                return $subscription;
            }

            /** @var PushSubscription $createdSubscription */
            $createdSubscription = $user->pushSubscriptions()->create([
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
            ]);

            return $createdSubscription;
        });
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

        return $host !== null && in_array($host, $this->allowedEndpointHosts(), true);
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
            $normalizedHost = $this->normalizeHost($host);

            if ($normalizedHost !== null) {
                $normalizedHosts[] = $normalizedHost;
            }
        }

        return array_values(array_unique($normalizedHosts));
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
