<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use App\Services\SupportTriage\EquipmentClassification;

/** A classifier that always answers with one given result. Used only to preview a rule before it is saved. */
final readonly class FixedClassifier implements EquipmentClassifier
{
    public function __construct(private EquipmentClassification $classification) {}

    public function classifyItem(string $itemName, ?string $note = null, ?string $apparatusType = null, bool $significant = false, ?string $condition = null): EquipmentClassification
    {
        return $this->classification;
    }

    public function classifyText(string $text, string $context): EquipmentClassification
    {
        return $this->classification;
    }

    public function policyVersion(): int
    {
        return 0;
    }
}
