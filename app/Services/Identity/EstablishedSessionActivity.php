<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Carbon\CarbonImmutable;

/**
 * Compares one account's sign-in sessions across a release window. Members keep using the Hub while a
 * release is checked, so three things are their own activity and not a change the release made:
 *
 *  - a new session, issued at the account's current security version (a sign-in);
 *  - a session the member signed out of;
 *  - a session that had already run out (idle or absolute) and was closed on the member's next request.
 *
 * Everything else is a change: a session that disappears, an existing session whose security version,
 * context or absolute expiry moves, a live session closed as "no longer current" (what a release that
 * breaks session checks would do), or a session closed for any other reason (password, role, admin).
 */
final class EstablishedSessionActivity
{
    public const SAME = 'same';

    public const ACTIVITY = 'activity';

    public const CHANGED = 'changed';

    /** The idle deadline slides while a member is active, so it is the one field not compared as is. */
    private const STABLE_FIELDS = ['id', 'security_version', 'context_class', 'absolute_expires_at', 'revoked_at', 'revoked_reason'];

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return self::SAME|self::ACTIVITY|self::CHANGED
     */
    public function classify(array $before, array $after, int $accountSecurityVersion): string
    {
        $beforeById = $this->keyed($before);
        $afterById = $this->keyed($after);
        $activity = false;

        foreach ($beforeById as $id => $baseline) {
            $current = $afterById[$id] ?? null;
            if ($current === null) {
                return self::CHANGED;
            }
            if ($this->stable($baseline) === $this->stable($current)) {
                continue;
            }
            if ($baseline['revoked_at'] !== null || ! $this->closedByMember($baseline, $current)) {
                return self::CHANGED;
            }
            $activity = true;
        }

        foreach (array_diff_key($afterById, $beforeById) as $current) {
            $signedIn = (int) $current['security_version'] === $accountSecurityVersion
                && ($current['revoked_at'] === null || $this->closedByMember($current, $current));
            if (! $signedIn) {
                return self::CHANGED;
            }
            $activity = true;
        }

        return $activity ? self::ACTIVITY : self::SAME;
    }

    /**
     * Only the close itself may differ: the session was open, and is now closed by a sign-out or because it
     * had run out by the time it was closed.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     */
    private function closedByMember(array $baseline, array $current): bool
    {
        $open = $this->stable([...$baseline, 'revoked_at' => null, 'revoked_reason' => null]);
        if ($open !== $this->stable([...$current, 'revoked_at' => null, 'revoked_reason' => null]) || $current['revoked_at'] === null) {
            return false;
        }

        return match ($current['revoked_reason']) {
            SessionRegistry::MEMBER_SIGNED_OUT => true,
            SessionRegistry::NO_LONGER_CURRENT => $this->hadRunOut($current),
            default => false,
        };
    }

    /** @param array<string, mixed> $session */
    private function hadRunOut(array $session): bool
    {
        $closedAt = CarbonImmutable::parse((string) $session['revoked_at']);
        foreach (['idle_expires_at', 'absolute_expires_at'] as $deadline) {
            if ($session[$deadline] !== null && ! $closedAt->isBefore(CarbonImmutable::parse((string) $session[$deadline]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function stable(array $session): array
    {
        return array_intersect_key($session, array_flip(self::STABLE_FIELDS));
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $sessions): array
    {
        $keyed = [];
        foreach ($sessions as $session) {
            $keyed[(string) $session['id']] = $session;
        }

        return $keyed;
    }
}
