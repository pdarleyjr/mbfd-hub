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
    public function __construct(private readonly ConferenceProvider $provider) {}

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
