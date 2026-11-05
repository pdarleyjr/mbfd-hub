<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Model;

/** The final text of one successful Workers AI call, plus the ledger row that metered it. */
final readonly class ModelReply
{
    public function __construct(
        public string $text,
        public int $modelCallId,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
