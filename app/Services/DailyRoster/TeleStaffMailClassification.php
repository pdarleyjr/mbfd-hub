<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

/**
 * The verdict on one inbound message. Reasons are machine codes, never mail content. Evidence holds the
 * authentication headers and envelope sender: sender and domain data, never member data.
 */
final readonly class TeleStaffMailClassification
{
    public const MATCH = 'match';

    public const NEEDS_REVIEW = 'needs_review';

    public const REJECTED = 'rejected';

    public const ORDINARY = 'ordinary';

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $verdict,
        public array $reasons = [],
        public array $evidence = [],
    ) {}

    public function isOrdinary(): bool
    {
        return $this->verdict === self::ORDINARY;
    }

    public function isMatch(): bool
    {
        return $this->verdict === self::MATCH;
    }

    /**
     * What `roster_imports.classification` keeps, so the proof outlives the email.
     *
     * @return array{verdict: string, reasons: list<string>, evidence?: array<string, mixed>}
     */
    public function toLedger(): array
    {
        $ledger = ['verdict' => $this->verdict, 'reasons' => $this->reasons];

        return $this->evidence === [] ? $ledger : [...$ledger, 'evidence' => $this->evidence];
    }
}
