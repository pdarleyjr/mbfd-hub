<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use DateTimeInterface;

/**
 * What the engine may know about one submission. Built by the domain adapter from the database, scoped to
 * the requester. Member text is used for classification only; it is never stored by the engine or logged.
 */
final readonly class IntakeFacts
{
    /**
     * @param  'vehicle'|'station'|'support'  $domain
     * @param  'inspection_findings'|'issue_message'|'station_request'|'supply_request'|'support_message'  $kind
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public string $domain,
        public string $kind,
        public ?int $requesterUserId,
        public array $findings = [],
        public ?string $memberText = null,
        public ?string $category = null,
        public bool $alreadyAnswered = false,
        public ?string $ineligibleReason = null,
        public bool $routed = false,
        public ?DateTimeInterface $submittedAt = null,
        // Conversation the reply lands in (message kinds only); caps automated replies per conversation.
        public ?string $threadKey = null,
    ) {}

    public function eligible(): bool
    {
        return $this->ineligibleReason === null && ! $this->alreadyAnswered;
    }
}
