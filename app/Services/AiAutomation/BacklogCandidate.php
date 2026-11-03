<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use DateTimeInterface;

/** An older submission that never got a staff or assistant response. */
final readonly class BacklogCandidate
{
    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $eventKey,
        public ?DateTimeInterface $submittedAt = null,
    ) {}
}
