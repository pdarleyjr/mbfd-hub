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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConferenceSessionService
{
    public function __construct(
        private readonly ConferenceProvider $provider,
        private readonly LiveKitProfileConfiguration $livekit,
    ) {}

    public function lineup(Employee $creator): VideoConferenceSession
    {
        $localNow = CarbonImmutable::now((string) config('video-conferencing.timezone'));
        $date = $localNow->toDateString();
        $key = 'lineup:'.$date;

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

    public function activeLineup(): ?VideoConferenceSession
    {
        $date = CarbonImmutable::now((string) config('video-conferencing.timezone'))->toDateString();
        $session = VideoConferenceSession::query()
            ->where('active_key', 'lineup:'.$date)
            ->whereNull('ended_at')
            ->first();

        return $session === null ? null : $this->requireProvisioned($session);
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
            'ends_at' => $session->started_at?->addMinutes(
                max(5, min(30, (int) config('video-conferencing.lineup_max_minutes', 15))),
            )->toIso8601String(),
        ];
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
