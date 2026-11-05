<?php

declare(strict_types=1);

namespace App\Services\SupportTriage\Contracts;

use App\Services\SupportTriage\EquipmentClassification;

/**
 * Deterministic classification from the admin-managed equipment catalog (support_equipment_rules).
 *
 * Precedence, highest first:
 *  1. Immediate safety hazard or verified readiness override (record flagged significant / out of service,
 *     or approved hazard phrases such as an uncontrolled leak, sparking, smoke or injury).
 *  2. HIGH protective-equipment categories (complete SCBA, facepiece/mask, hood, helmet, bunker coat/pants).
 *  3. An item-specific catalog rule (apparatus-scoped rule before an everywhere rule).
 *  4. Specialized / medium-cost categories.
 *  5. Approved low-risk categories.
 *  6. Safe fallback for unknown or ambiguous items: MEDIUM, unclassified = true (never silently LOW).
 * Never calls a model. Reads the catalog from the database (memoized per request only).
 */
interface EquipmentClassifier
{
    /**
     * One checkout finding. $condition is missing|damaged|other; $apparatusType is the checklist type
     * (engine, engine2, ladder1, ladder3, rescue, fireboat6, default) when known.
     */
    public function classifyItem(string $itemName, ?string $note = null, ?string $apparatusType = null, bool $significant = false, ?string $condition = null): EquipmentClassification;

    /** Free text (a station request, supply request or Support Services message); $context is station|supply|support. */
    public function classifyText(string $text, string $context): EquipmentClassification;

    /** Current catalog version (ai_automation_settings.triage_policy_version). */
    public function policyVersion(): int;
}
