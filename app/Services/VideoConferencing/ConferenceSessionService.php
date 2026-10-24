<?php

namespace App\Services\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Enums\VideoConferencing\ConferenceRoomType;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Models\Employee;
use App\Models\VideoConferenceSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConferenceSessionService
{
    /** How long an unprovisioned lineup row may read as "starting" to members. */
    private const STARTING_WINDOW_SECONDS = 120;

    public function __construct(
        private readonly ConferenceProvider $provider,
        private readonly LiveKitProfileConfiguration $livekit,
        private readonly LineupTimeLimit $timeLimit,
        private readonly ConferenceSeatService $seats,
        private readonly ConferenceIdentityService $identities,
    ) {}

    public function lineup(Employee $creator): VideoConferenceSession
    {
        $localNow = CarbonImmutable::now((string) config('video-conferencing.timezone'));
        $date = $localNow->toDateString();
        $key = 'lineup:'.$date;

        foreach ($this->openLineupRows($localNow) as $open) {
            // A row past its limit (the expiry command is late or down) must not be handed
            // back: the token gate would answer 410 for it. Close it and start a fresh lineup.
            if ($this->timeLimit->hasExpired($open)) {
                $this->closeOverdue($open);

                continue;
            }
            // A lineup started before midnight and still inside its limit is the running lineup.
            if ($open->logical_key !== $key) {
                return $this->requireProvisioned($open);
            }
        }

        return $this->activeSession(
            type: ConferenceRoomType::Lineup,
            logicalKey: $key,
            roomPrefix: 'mbfd-lineup-'.$date,
            creator: $creator,
            target: null,
            scheduledFor: $this->scheduledFor($localNow),
        );
    }

    public function startLineup(Employee $creator): VideoConferenceSession
    {
        return $this->lineup($creator);
    }

    /**
     * The unfinished lineup, if any: today's, or yesterday's while it is still inside its
     * time limit (a lineup started at 23:50 Eastern runs past midnight). Today's unfinished
     * row is returned even when it is past its limit and the expiry command has not closed
     * it yet, so 300 can still end it; the token gate refuses to join such a lineup.
     */
    public function activeLineup(): ?VideoConferenceSession
    {
        $localNow = CarbonImmutable::now((string) config('video-conferencing.timezone'));
        $today = 'lineup:'.$localNow->toDateString();
        foreach ($this->openLineupRows($localNow) as $session) {
            if ($session->logical_key === $today || ! $this->timeLimit->hasExpired($session)) {
                return $this->requireProvisioned($session);
            }
        }

        return null;
    }

    /**
     * Ends every unfinished lineup that is past its time limit, whatever day it started on.
     * Date-independent on purpose: a lineup begun at 23:50 Eastern is closed at 00:05 even
     * though the calendar key it was created under is no longer "today".
     *
     * @return list<VideoConferenceSession>
     */
    public function expireOverdueLineups(): array
    {
        $expired = [];
        $rows = VideoConferenceSession::query()
            ->where('type', ConferenceRoomType::Lineup->value)
            ->whereNull('ended_at')
            ->whereNotNull('active_key')
            ->get();
        foreach ($rows as $session) {
            if (! $this->timeLimit->hasExpired($session)) {
                continue;
            }
            $this->closeOverdue($session);
            $expired[] = $session;
        }

        return $expired;
    }

    /** @return Collection<int, VideoConferenceSession> */
    private function openLineupRows(CarbonImmutable $localNow): Collection
    {
        return VideoConferenceSession::query()
            ->whereIn('active_key', ['lineup:'.$localNow->toDateString(), 'lineup:'.$localNow->subDay()->toDateString()])
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    private function closeOverdue(VideoConferenceSession $session): void
    {
        if ($session->provisioned_at !== null) {
            $this->end($session);

            return;
        }
        // Never provisioned (for example a crash during start): there is no room to close.
        $session->forceFill(['active_key' => null, 'ended_at' => now()])->save();
    }

    public function end(VideoConferenceSession $session): void
    {
        if ($session->ended_at !== null || $session->active_key === null) {
            return;
        }

        $this->provider->closeRoom($session->livekit_room_name);
        DB::transaction(function () use ($session): void {
            $endedAt = now();
            $session->participations()->whereNull('left_at')->update([
                'active_identity_key' => null,
                'left_at' => $endedAt,
            ]);
            $session->forceFill([
                'active_key' => null,
                'ended_at' => $endedAt,
            ])->save();
        });
    }

    /** @return array<string, mixed> */
    public function lineupStatus(?VideoConferenceSession $session = null): array
    {
        $session ??= $this->activeLineup();
        if ($session === null) {
            return ['active' => false, 'session_id' => null, 'started_at' => null, 'ends_at' => null];
        }

        return [
            'active' => true,
            'session_id' => $session->id,
            'started_at' => $session->started_at?->toIso8601String(),
            'ends_at' => $this->lineupEndsAt($session),
        ];
    }

    /**
     * What an ordinary member may know about today's Morning Lineup before and
     * after joining. Database only: it never calls LiveKit, never creates a
     * session, participation or token, and never returns room names, people,
     * stations, usage or provider details.
     *
     * Day boundary: "today" is the America/New_York service date, the same one the 300
     * start uses. A lineup belongs to the day it started on and stays live until its own
     * time limit, so one begun at 23:50 still reads active at 00:03 and is `ended` at
     * 00:05. A lineup from the previous day that is over (ended or past its limit) reads
     * `not_started`, never `ended`. A lineup past its limit reads `ended` even if the
     * expiry command has not closed the row yet.
     *
     * @return array{state: string, session_id: ?string, started_at: ?string, ends_at: ?string, ended_at: ?string, scheduled_for: ?string, full: bool, server_time: string}
     */
    public function memberLineupStatus(?Employee $viewer = null): array
    {
        $localNow = CarbonImmutable::now((string) config('video-conferencing.timezone'));
        $todayKey = 'lineup:'.$localNow->toDateString();

        // At most one unfinished lineup exists per service day (unique active_key). A running
        // lineup wins; otherwise today's latest row tells "ended" apart from "not started".
        $session = $this->openLineupRows($localNow)->first(fn (VideoConferenceSession $open): bool => ! $this->timeLimit->hasExpired($open))
            ?? VideoConferenceSession::query()
                ->where('logical_key', $todayKey)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();

        $state = $this->memberLineupState($session);
        $active = $state === 'active';
        $endsAt = $session === null ? null : $this->timeLimit->endsAt($session);

        return [
            'state' => $state,
            // Only an active lineup can be joined; a starting one cannot, so it gets no id.
            'session_id' => $active ? $session->id : null,
            'started_at' => $session?->started_at?->toIso8601String(),
            'ends_at' => $active ? $endsAt?->toIso8601String() : null,
            'ended_at' => $state === 'ended' ? ($session->ended_at ?? $endsAt)?->toIso8601String() : null,
            'scheduled_for' => ($session->scheduled_for ?? $this->scheduledFor($localNow))?->toIso8601String(),
            'full' => $active && $this->seats->isFull(
                $session,
                $viewer === null ? null : $this->identities->identity(ConferenceJoinRole::Self, $viewer),
            ),
            'server_time' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    private function memberLineupState(?VideoConferenceSession $session): string
    {
        if ($session === null) {
            return 'not_started';
        }
        if ($session->ended_at !== null || $session->active_key === null || $this->timeLimit->hasExpired($session)) {
            return 'ended';
        }
        if ($session->provisioned_at === null) {
            // A row that never finished provisioning (for example after a crash) must not
            // read as "starting" forever; the start path removes the row on a normal failure.
            return $session->started_at !== null && $session->started_at->diffInSeconds(now(), true) <= self::STARTING_WINDOW_SECONDS
                ? 'starting'
                : 'unavailable';
        }

        try {
            return hash_equals((string) $session->livekit_profile, $this->livekit->profile()) ? 'active' : 'unavailable';
        } catch (ConferenceUnavailableException) {
            // The configured LiveKit profile is invalid: a status read must say so, not fail with a 500.
            return 'unavailable';
        }
    }

    private function lineupEndsAt(VideoConferenceSession $session): ?string
    {
        return $this->timeLimit->endsAt($session)?->toIso8601String();
    }

    /** @return array<string, mixed> */
    public function sessionStatus(?VideoConferenceSession $session): array
    {
        if ($session === null) {
            return [
                'active' => false,
                'session_id' => null,
                'started_at' => null,
                'ends_at' => null,
                'type' => null,
                'target_station' => null,
            ];
        }

        return [
            'active' => true,
            'session_id' => $session->id,
            'started_at' => $session->started_at?->toIso8601String(),
            'ends_at' => null,
            'type' => $session->type->value,
            'target_station' => $session->target_station,
        ];
    }

    /** @return array<string, mixed> */
    public function sessionPayload(VideoConferenceSession $session): array
    {
        return [
            'id' => $session->id,
            'type' => $session->type->value,
            'target_station' => $session->target_station,
            'scheduled_for' => $session->scheduled_for?->toIso8601String(),
            'lineup_time_configured' => config('video-conferencing.lineup_time') !== null,
            // The time limit of the session actually being joined, so a banner never shows another lineup's end.
            'ends_at' => $session->type === ConferenceRoomType::Lineup ? $this->lineupEndsAt($session) : null,
        ];
    }

    public function direct(Employee $creator, ConferenceJoinRole $station): VideoConferenceSession
    {
        abort_unless($station->isStation(), 422, 'A supported station is required.');

        return $this->activeSession(
            type: ConferenceRoomType::Direct,
            logicalKey: 'direct:'.$station->value,
            roomPrefix: 'mbfd-direct-'.$station->value,
            creator: $creator,
            target: $station,
            scheduledFor: null,
        );
    }

    public function activeDirectForStation(ConferenceJoinRole $station): VideoConferenceSession
    {
        abort_unless($station->isStation(), 422, 'A supported station is required.');

        return $this->requireProvisioned(
            VideoConferenceSession::query()
                ->where('active_key', 'direct:'.$station->value)
                ->firstOrFail(),
        );
    }

    public function activeDirectForStationOrNull(ConferenceJoinRole $station): ?VideoConferenceSession
    {
        abort_unless($station->isStation(), 422, 'A supported station is required.');
        $session = VideoConferenceSession::query()
            ->where('active_key', 'direct:'.$station->value)
            ->whereNull('ended_at')
            ->first();

        return $session === null ? null : $this->requireProvisioned($session);
    }

    private function activeSession(
        ConferenceRoomType $type,
        string $logicalKey,
        string $roomPrefix,
        Employee $creator,
        ?ConferenceJoinRole $target,
        ?CarbonImmutable $scheduledFor,
    ): VideoConferenceSession {
        $existing = VideoConferenceSession::query()->where('active_key', $logicalKey)->first();
        if ($existing !== null) {
            return $this->requireProvisioned($existing);
        }

        try {
            $session = DB::transaction(fn (): VideoConferenceSession => VideoConferenceSession::query()->create([
                'type' => $type,
                'logical_key' => $logicalKey,
                'active_key' => $logicalKey,
                'livekit_profile' => $this->livekit->profile(),
                'livekit_room_name' => $roomPrefix.'-'.Str::lower(Str::random(12)),
                'target_station' => $target?->value,
                'scheduled_for' => $scheduledFor,
                'created_by_employee_id' => $creator->getKey(),
                'started_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->requireProvisioned(
                VideoConferenceSession::query()->where('active_key', $logicalKey)->firstOrFail(),
            );
        }

        try {
            $this->provider->createRoom($session->livekit_room_name, json_encode([
                'session_id' => $session->id,
                'type' => $type->value,
                'target_station' => $target?->value,
            ], JSON_THROW_ON_ERROR));
            $session->forceFill(['provisioned_at' => now()])->save();
        } catch (\Throwable $exception) {
            $session->delete();
            throw $exception;
        }

        return $session;
    }

    private function requireProvisioned(VideoConferenceSession $session): VideoConferenceSession
    {
        if ($session->provisioned_at === null) {
            throw new ConferenceUnavailableException('The conference room is still being prepared. Try again.');
        }
        if (! hash_equals((string) $session->livekit_profile, $this->livekit->profile())) {
            throw new ConferenceUnavailableException('The active conference uses a different LiveKit profile. End it before switching profiles.');
        }

        return $session;
    }

    private function scheduledFor(CarbonImmutable $now): ?CarbonImmutable
    {
        $time = config('video-conferencing.lineup_time');
        if (! is_string($time) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }

        return $now->setTimeFromTimeString($time)->utc();
    }
}
