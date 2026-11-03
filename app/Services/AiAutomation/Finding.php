<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/** One vehicle inventory/equipment finding on a checkout, as recorded by the member. */
final readonly class Finding
{
    /**
     * @param  'missing'|'damaged'|'other'  $condition
     */
    public function __construct(
        public string $itemName,
        public string $condition,
        public bool $significant = false,
        public bool $isNew = true,
        public ?string $note = null,
    ) {}
}
