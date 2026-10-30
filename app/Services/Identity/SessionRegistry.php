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

    /**
     * Slides the idle deadline forward while the member is using the Hub. Call only after isCurrent()
     * accepted the session: a session that is already past its idle or absolute time is never revived.
     * The deadline never moves past the absolute expiry and never moves backwards, and the row is
     * written at most once a minute, so a busy page does not turn into a stream of updates.
     */
    public function recordActivity(AuthenticationSession $session, CarbonInterface $at): void
    {
        $now = CarbonImmutable::instance($at);
        $last = $this->timestamp($session, 'last_activity_at');
        if ($last !== null && $last->greaterThan($now->subSeconds(60))) {
            return; // already recorded within the last minute: no query at all on most requests
        }
        $window = max(60, (int) config('security.canonical_session.unmanaged_idle_seconds', 14400));
        $deadline = $now->addSeconds($window);

        $absolute = $this->timestamp($session, 'absolute_expires_at');
        if ($absolute !== null && $deadline->greaterThan($absolute)) {
            $deadline = $absolute;
        }
        $current = $this->timestamp($session, 'idle_expires_at');
        if ($current !== null && $current->greaterThan($deadline)) {
            $deadline = $current;
        }

        AuthenticationSession::query()
            ->whereKey($session->getKey())
            ->whereNull('revoked_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<=', $now->subSeconds(60));
            })
            ->update([
                'last_activity_at' => $now,
                'idle_expires_at' => $deadline,
                'updated_at' => $now,
            ]);
    }

    public function revoke(
        User $user,
        string $registryId,
        string $reason,
        CarbonInterface $at,
    ): void {
        AuthenticationSession::query()
            ->whereKey($registryId)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $at,
                'revoked_reason' => $reason,
                'updated_at' => $at,
            ]);
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
