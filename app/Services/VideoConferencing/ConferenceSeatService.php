<?php

declare(strict_types=1);

namespace App\Services\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;

/**
 * Database-only count of the individual member places in one lineup. The room
 * keeps six places for 300 and the five stations (see the runbook), so only
 * `self` participations are counted here and a station can never be refused
 * because members filled the room.
 */
class ConferenceSeatService
{
    /** Extra seconds past the token lifetime before an unconfirmed join stops holding a seat. */
    private const UNCONFIRMED_GRACE_SECONDS = 90;

    public function limit(): int
    {
        $configured = max(1, (int) config('video-conferencing.member_seats', 6));
        $room = (int) config('video-conferencing.livekit.max_participants', 12);
        $reserved = max(0, (int) config('video-conferencing.livekit.reserved_endpoint_slots', 6));

        return max(1, min($configured, $room - $reserved));
    }

    /**
     * Seats held right now. A participation holds one from the moment its token is
     * issued until it leaves. One whose browser never confirmed (no `joined_at`,
     * for example a failed connect whose leave call was lost) frees its seat when
     * its token could no longer have been used, so an orphan cannot block the room.
     *
     * @param  string|null  $exceptIdentity  a member's own identity: re-joining never competes with itself
     */
    public function occupied(VideoConferenceSession $session, ?string $exceptIdentity = null): int
    {
        $ttl = max(60, min(900, (int) config('video-conferencing.livekit.token_ttl_seconds', 600)));

        return VideoConferenceParticipation::query()
            ->where('session_id', $session->getKey())
            ->where('join_as', ConferenceJoinRole::Self->value)
            ->whereNotNull('active_identity_key')
            ->whereNull('left_at')
            ->where(function ($query) use ($ttl): void {
                $query->whereNotNull('joined_at')
                    ->orWhere('token_issued_at', '>=', now()->subSeconds($ttl + self::UNCONFIRMED_GRACE_SECONDS));
            })
            ->when($exceptIdentity !== null, fn ($query) => $query->where('participant_identity', '!=', $exceptIdentity))
            ->count();
    }

    public function isFull(VideoConferenceSession $session, ?string $exceptIdentity = null): bool
    {
        return $this->occupied($session, $exceptIdentity) >= $this->limit();
    }
}
