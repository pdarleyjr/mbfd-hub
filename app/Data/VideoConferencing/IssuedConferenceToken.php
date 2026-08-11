<?php

namespace App\Data\VideoConferencing;

use Carbon\CarbonImmutable;

final readonly class IssuedConferenceToken
{
    public function __construct(
        public string $token,
        public CarbonImmutable $expiresAt,
    ) {}
}
