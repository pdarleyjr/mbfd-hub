<?php

namespace App\Services\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Models\VideoConferenceEvent;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;

class ConferenceWebhookService
{
    public function __construct(private readonly ConferenceProvider $provider) {}

    public function handle(string $body, ?string $authorization): bool
    {
        $event = $this->provider->verifyWebhook($body, $authorization);
        $session = $event->roomName === null ? null : VideoConferenceSession::query()
            ->where('livekit_room_name', $event->roomName)
            ->first();

        $created = VideoConferenceEvent::query()->firstOrCreate(
            ['provider_event_id' => $event->id],
            [
                'event_type' => $event->event,
                'session_id' => $session?->id,
                'participant_identity' => $event->participantIdentity,
                'occurred_at' => $event->occurredAt,
            ],
        );

        if (! $created->wasRecentlyCreated || $session === null) {
            return false;
        }

        if ($event->participantIdentity !== null && $event->event === 'participant_joined') {
            VideoConferenceParticipation::query()
                ->where('session_id', $session->id)
                ->where('participant_identity', $event->participantIdentity)
                ->whereNotNull('active_identity_key')
                ->latest('token_issued_at')
                ->first()?->forceFill(['joined_at' => $event->occurredAt])->save();
        }

        if ($event->participantIdentity !== null && in_array($event->event, ['participant_left', 'participant_connection_aborted'], true)) {
            VideoConferenceParticipation::query()
                ->where('session_id', $session->id)
                ->where('participant_identity', $event->participantIdentity)
                ->whereNotNull('active_identity_key')
                ->update(['active_identity_key' => null, 'left_at' => $event->occurredAt]);
        }

        if ($event->event === 'room_finished') {
            $session->forceFill(['active_key' => null, 'ended_at' => $event->occurredAt])->save();
        }

        return true;
    }
}
