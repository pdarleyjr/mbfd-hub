<?php

declare(strict_types=1);

namespace App\Services\Bid;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class BidAuthorizationCodeBroker
{
    private const CACHE_PREFIX = 'bid:authorization-code:';

    public function issue(
        User $user,
        Employee $employee,
        string $clientId,
        string $redirectUri,
    ): string {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = (int) config('services.bid.authorization.code_ttl_seconds', 60);
        $now = now()->getTimestamp();

        $stored = Cache::put($this->cacheKey($code), [
            'issuer' => (string) config('services.bid.authorization.issuer'),
            'audience' => $clientId,
            'redirect_uri' => $redirectUri,
            'user_id' => (int) $user->getKey(),
            'employee_profile_id' => (int) $employee->getKey(),
            'security_version' => (int) $user->security_version,
            'issued_at' => $now,
            'expires_at' => $now + $ttl,
        ], $ttl);

        if (! $stored) {
            throw new RuntimeException('Bid authorization code storage is unavailable.');
        }

        return $code;
    }

    /**
     * @return array{
     *     issuer: string,
     *     audience: string,
     *     redirect_uri: string,
     *     user_id: int,
     *     employee_profile_id: int,
     *     security_version: int,
     *     issued_at: int,
     *     expires_at: int
     * }|null
     */
    public function redeem(string $code, string $clientId, string $redirectUri): ?array
    {
        $key = $this->cacheKey($code);

        try {
            return Cache::lock($key.':redeem', 5)->block(1, function () use (
                $key,
                $clientId,
                $redirectUri,
            ): ?array {
                $record = Cache::get($key);

                if (! $this->isValidRecord($record, $clientId, $redirectUri)) {
                    return null;
                }

                Cache::forget($key);

                return $record;
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function cacheKey(string $code): string
    {
        return self::CACHE_PREFIX.hash('sha256', $code);
    }

    private function isValidRecord(mixed $record, string $clientId, string $redirectUri): bool
    {
        if (! is_array($record)) {
            return false;
        }

        return ($record['issuer'] ?? null) === (string) config('services.bid.authorization.issuer')
            && ($record['audience'] ?? null) === $clientId
            && ($record['redirect_uri'] ?? null) === $redirectUri
            && is_int($record['user_id'] ?? null)
            && is_int($record['employee_profile_id'] ?? null)
            && is_int($record['security_version'] ?? null)
            && is_int($record['issued_at'] ?? null)
            && is_int($record['expires_at'] ?? null)
            && $record['expires_at'] >= now()->getTimestamp();
    }
}
