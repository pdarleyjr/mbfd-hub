<?php

namespace Tests\Fakes;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Data\VideoConferencing\ConferenceParticipant;
use App\Data\VideoConferencing\IssuedConferenceToken;
use App\Data\VideoConferencing\VerifiedConferenceWebhook;
use Carbon\CarbonImmutable;

class FakeConferenceProvider implements ConferenceProvider
{
    /** @var list<array{room: string, metadata: string}> */
    public array $createdRooms = [];

    /** @var list<string> */
    public array $closedRooms = [];

    /** @var list<array{room: string, identity: string, name: string, metadata: string}> */
    public array $issuedTokens = [];

    /** @var array<string, list<ConferenceParticipant>> */
    public array $roomParticipants = [];

    /** @var list<array{room: string, identity: string}> */
    public array $removedParticipants = [];

    /** @var list<array{room: string, identity: string}> */
    public array $mutedParticipants = [];

    public ?VerifiedConferenceWebhook $webhook = null;

    public bool $healthy = true;

    public function createRoom(string $roomName, string $metadata): void
    {
        $this->createdRooms[] = ['room' => $roomName, 'metadata' => $metadata];
    }

    public function closeRoom(string $roomName): void
    {
        $this->closedRooms[] = $roomName;
        unset($this->roomParticipants[$roomName]);
    }

    public function issueToken(string $roomName, string $identity, string $displayName, string $metadata): IssuedConferenceToken
    {
        $this->issuedTokens[] = [
            'room' => $roomName,
            'identity' => $identity,
            'name' => $displayName,
            'metadata' => $metadata,
        ];

        return new IssuedConferenceToken('signed-test-token', CarbonImmutable::now()->addMinutes(10));
    }

    public function participants(string $roomName): array
    {
        return $this->roomParticipants[$roomName] ?? [];
    }

    public function participantExists(string $roomName, string $identity): bool
    {
        return collect($this->participants($roomName))->contains(
            fn (ConferenceParticipant $participant): bool => $participant->identity === $identity,
        );
    }

    public function removeParticipant(string $roomName, string $identity): void
    {
        $this->removedParticipants[] = compact('roomName', 'identity');
        $this->roomParticipants[$roomName] = collect($this->participants($roomName))
            ->reject(fn (ConferenceParticipant $participant): bool => $participant->identity === $identity)
            ->values()
            ->all();
    }

    public function muteMicrophone(string $roomName, string $identity): void
    {
        $this->mutedParticipants[] = ['room' => $roomName, 'identity' => $identity];
    }

    public function verifyWebhook(string $body, ?string $authorization): VerifiedConferenceWebhook
    {
        return $this->webhook ?? throw new \RuntimeException('No fake webhook configured.');
    }

    public function healthCheck(): bool
    {
        return $this->healthy;
    }
}
