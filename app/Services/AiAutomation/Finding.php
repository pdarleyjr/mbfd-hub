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
        // Checklist type of the unit (engine, engine2, ladder1, ladder3, rescue, fireboat6, default) when known.
        public ?string $apparatusType = null,
        // The defect this finding is recorded on, so the reply engine reads the same triage level staff see.
        public ?int $defectId = null,
    ) {}
}
