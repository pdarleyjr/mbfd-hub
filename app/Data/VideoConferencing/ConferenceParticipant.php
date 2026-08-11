<?php

namespace App\Data\VideoConferencing;

final readonly class ConferenceParticipant
{
    /** @param list<array{sid: string, source: int, muted: bool}> $tracks */
    public function __construct(
        public string $identity,
        public string $name,
        public array $tracks = [],
    ) {}
}
