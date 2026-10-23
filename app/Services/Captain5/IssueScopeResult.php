<?php

declare(strict_types=1);

namespace App\Services\Captain5;

/** Outcome of Captain5IssueScope::classify(). */
final readonly class IssueScopeResult
{
    public function __construct(
        public bool $inScope,
        public ?string $kind,
        public string $checklistType,
        public ?string $itemKey,
        public ?string $classification,
        public ?string $reason = null,
    ) {}

    public static function outOfScope(string $reason, string $checklistType = 'unmapped'): self
    {
        return new self(false, null, $checklistType, null, null, $reason);
    }
}
