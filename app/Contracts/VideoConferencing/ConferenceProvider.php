<?php

namespace App\Contracts\VideoConferencing;

use App\Data\VideoConferencing\ConferenceParticipant;
use App\Data\VideoConferencing\IssuedConferenceToken;
use App\Data\VideoConferencing\VerifiedConferenceWebhook;

interface ConferenceProvider
{
    public function createRoom(string $roomName, string $metadata): void;

    public function closeRoom(string $roomName): void;

    public function issueToken(
        string $roomName,
        string $identity,
        string $displayName,
        string $metadata,
    ): IssuedConferenceToken;

    /** @return list<ConferenceParticipant> */
    public function participants(string $roomName): array;

    public function participantExists(string $roomName, string $identity): bool;

    public function removeParticipant(string $roomName, string $identity): void;

    public function muteMicrophone(string $roomName, string $identity): void;

    public function verifyWebhook(string $body, ?string $authorization): VerifiedConferenceWebhook;

    public function healthCheck(): bool;
}
