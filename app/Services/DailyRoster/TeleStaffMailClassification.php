<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

/** The verdict on one inbound message. Reasons are machine codes, never mail content. */
final readonly class TeleStaffMailClassification
{
    public const MATCH = 'match';

    public const NEEDS_REVIEW = 'needs_review';

    public const REJECTED = 'rejected';

    public const ORDINARY = 'ordinary';

    /** @param list<string> $reasons */
    public function __construct(
        public string $verdict,
        public array $reasons = [],
    ) {}

    public function isOrdinary(): bool
    {
        return $this->verdict === self::ORDINARY;
    }

    public function isMatch(): bool
    {
        return $this->verdict === self::MATCH;
    }
}
