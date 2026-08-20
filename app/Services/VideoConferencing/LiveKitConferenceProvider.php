<?php

namespace App\Services\VideoConferencing;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use Agence104\LiveKit\WebhookReceiver;
use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Data\VideoConferencing\ConferenceParticipant;
use App\Data\VideoConferencing\IssuedConferenceToken;
use App\Data\VideoConferencing\VerifiedConferenceWebhook;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use Carbon\CarbonImmutable;
use Livekit\TrackSource;
use Throwable;

class LiveKitConferenceProvider implements ConferenceProvider
{
    public function __construct(private readonly ?LiveKitProfileConfiguration $configuration = null) {}

    public function createRoom(string $roomName, string $metadata): void
    {
        try {
            $options = (new RoomCreateOptions)
                ->setName($roomName)
                ->setMetadata($metadata)
                ->setEmptyTimeout((int) config('video-conferencing.livekit.empty_timeout_seconds'))
                ->setMaxParticipants((int) config('video-conferencing.livekit.max_participants'));
            $this->roomService()->createRoom($options);
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function closeRoom(string $roomName): void
    {
        try {
            $this->roomService()->deleteRoom($roomName);
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function issueToken(
        string $roomName,
        string $identity,
        string $displayName,
        string $metadata,
    ): IssuedConferenceToken {
        $ttl = max(60, min(900, (int) config('video-conferencing.livekit.token_ttl_seconds', 600)));

        try {
            $this->clientUrl();
            $options = (new AccessTokenOptions)
                ->setIdentity($identity)
                ->setName($displayName)
                ->setMetadata($metadata)
                ->setTtl($ttl);
            $grant = (new VideoGrant)
                ->setRoomJoin()
                ->setRoomName($roomName)
                ->setCanPublish()
                ->setCanSubscribe()
                ->setCanPublishData()
                ->setCanPublishSources(['camera', 'microphone', 'screen_share', 'screen_share_audio']);

            $token = (new AccessToken($this->apiKey(), $this->apiSecret(), $options))
                ->setGrant($grant)
                ->toJwt();

            return new IssuedConferenceToken($token, CarbonImmutable::now()->addSeconds($ttl));
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function participants(string $roomName): array
    {
        try {
            $participants = [];
            foreach ($this->roomService()->listParticipants($roomName)->getParticipants() as $participant) {
                $tracks = [];
                foreach ($participant->getTracks() as $track) {
                    $tracks[] = [
                        'sid' => $track->getSid(),
                        'source' => $track->getSource(),
                        'muted' => $track->getMuted(),
                    ];
                }
                $participants[] = new ConferenceParticipant(
                    identity: $participant->getIdentity(),
                    name: $participant->getName(),
                    tracks: $tracks,
                );
            }

            return $participants;
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function participantExists(string $roomName, string $identity): bool
    {
        foreach ($this->participants($roomName) as $participant) {
            if (hash_equals($participant->identity, $identity)) {
                return true;
            }
        }

        return false;
    }

    public function removeParticipant(string $roomName, string $identity): void
    {
        try {
            $this->roomService()->removeParticipant($roomName, $identity);
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function muteMicrophone(string $roomName, string $identity): void
    {
        $participant = collect($this->participants($roomName))
            ->first(fn (ConferenceParticipant $candidate): bool => hash_equals($candidate->identity, $identity));

        if ($participant === null) {
            return;
        }

        try {
            foreach ($participant->tracks as $track) {
                if ($track['source'] === TrackSource::MICROPHONE && ! $track['muted']) {
                    $this->roomService()->mutePublishedTrack($roomName, $identity, $track['sid'], true);
                }
            }
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function verifyWebhook(string $body, ?string $authorization): VerifiedConferenceWebhook
    {
        try {
            $token = preg_replace('/^Bearer\s+/i', '', (string) $authorization);
            $event = (new WebhookReceiver($this->apiKey(), $this->apiSecret()))
                ->receive($body, $token ?: null);

            return new VerifiedConferenceWebhook(
                id: $event->getId(),
                event: $event->getEvent(),
                roomName: $event->getRoom()?->getName(),
                participantIdentity: $event->getParticipant()?->getIdentity(),
                occurredAt: CarbonImmutable::createFromTimestampUTC((int) $event->getCreatedAt()),
            );
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function healthCheck(): bool
    {
        try {
            $this->roomService()->listRooms([]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function roomService(): RoomServiceClient
    {
        return new RoomServiceClient($this->apiUrl(), $this->apiKey(), $this->apiSecret());
    }

    private function apiUrl(): string
    {
        return $this->profileConfiguration()->apiUrl();
    }

    public function clientUrl(): string
    {
        return $this->profileConfiguration()->clientUrl();
    }

    private function apiKey(): string
    {
        return $this->profileConfiguration()->apiKey();
    }

    private function apiSecret(): string
    {
        return $this->profileConfiguration()->apiSecret();
    }

    private function profileConfiguration(): LiveKitProfileConfiguration
    {
        return $this->configuration ?? app(LiveKitProfileConfiguration::class);
    }

    private function unavailable(Throwable $exception): ConferenceUnavailableException
    {
        if ($exception instanceof ConferenceUnavailableException) {
            return $exception;
        }

        report($exception);

        return new ConferenceUnavailableException('The video conferencing service is temporarily unavailable.', 0, $exception);
    }
}
