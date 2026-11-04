<?php

declare(strict_types=1);

namespace App\Services\Defects;

/** Outcome of DefectOwnership::decide(): who works the issue and why. */
final readonly class OwnerDecision
{
    public function __construct(
        public string $owner,
        public string $basis,
        public string $checklistType,
        public ?string $itemKey,
    ) {}
}
