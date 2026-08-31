<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\SessionContextClass;
use App\Models\AuthenticationSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use LogicException;

final class SessionRegistry
{
    public function register(
        User $user,
        string $laravelSessionId,
        SessionContextClass $contextClass,
        CarbonInterface $issuedAt,
        CarbonInterface $idleExpiresAt,
        CarbonInterface $absoluteExpiresAt,
    ): AuthenticationSession {
        if (! $user->isAuthenticationAllowed()) {
            throw new LogicException('Authentication sessions may only be registered for active accounts.');
        }

        if ($laravelSessionId === '') {
            throw new LogicException('A Laravel session identifier is required.');
        }

        $session = new AuthenticationSession;
        $session->forceFill([
            'user_id' => $user->id,
            'session_id_hash' => $this->hashSessionId($laravelSessionId),
            'security_version' => $user->security_version,
            'context_class' => $contextClass,
            'issued_at' => $issuedAt,
            'last_activity_at' => $issuedAt,
            'idle_expires_at' => $idleExpiresAt,
            'absolute_expires_at' => $absoluteExpiresAt,
            'recent_auth_at' => $issuedAt,
        ]);
        $session->save();

        return $session;
    }

    public function isCurrent(User $user, AuthenticationSession $session, CarbonInterface $at): bool
    {
        $issuedAt = $this->timestamp($session, 'issued_at');
        $idleExpiresAt = $this->timestamp($session, 'idle_expires_at');
        $absoluteExpiresAt = $this->timestamp($session, 'absolute_expires_at');

        return $user->isAuthenticationAllowed()
            && $session->user_id === $user->id
            && $session->revoked_at === null
            && $session->security_version === $user->security_version
            && $issuedAt !== null
            && $idleExpiresAt !== null
            && $absoluteExpiresAt !== null
            && $issuedAt->lessThanOrEqualTo($at)
            && $idleExpiresAt->isAfter($at)
            && $absoluteExpiresAt->isAfter($at);
    }

    private function hashSessionId(string $laravelSessionId): string
    {
        return hash_hmac('sha256', $laravelSessionId, (string) config('app.key'));
    }

    private function timestamp(AuthenticationSession $session, string $column): ?CarbonImmutable
    {
        $value = $session->getRawOriginal($column);

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
