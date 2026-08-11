<?php

namespace App\Data\VideoConferencing;

use Carbon\CarbonImmutable;

final readonly class VerifiedConferenceWebhook
{
    public function __construct(
        public string $id,
        public string $event,
        public ?string $roomName,
        public ?string $participantIdentity,
        public CarbonImmutable $occurredAt,
    ) {}
}
