<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation\Support;

use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use App\Services\SupportTriage\EquipmentClassification as Cls;

/**
 * A tiny stand-in for the catalog classifier so the reply engine can be tested on its own. It follows the same
 * contract (level, category, handler, hazard, unclassified, memberResponse) with a handful of keyword rules.
 */
final class FakeEquipmentClassifier implements EquipmentClassifier
{
    public function classifyItem(string $itemName, ?string $note = null, ?string $apparatusType = null, bool $significant = false, ?string $condition = null): Cls
    {
        $cls = $this->match($itemName.' '.($note ?? ''));

        return $significant && $cls->level !== Cls::HIGH
            ? new Cls(Cls::HIGH, $cls->category, $cls->handler, reasons: ['significant_flag'], isCritical: true)
            : $cls;
    }

    public function classifyText(string $text, string $context): Cls
    {
        return $this->match($text);
    }

    public function policyVersion(): int
    {
        return 1;
    }

    private function match(string $text): Cls
    {
        $t = mb_strtolower($text);
        $has = static fn (string $pattern): bool => preg_match($pattern, $t) === 1;

        return match (true) {
            $has('/sparking|smoke|uncontrolled|gas smell|injur|electrical shock/') => new Cls(Cls::HIGH, 'other', 'support_services', reasons: ['hazard_override'], hazard: true),
            $has('/special widget/') => new Cls(Cls::MEDIUM, 'specialized_tool', 'support_services', memberResponse: 'Check the {item} cabinet, then call us.', ruleId: 77),
            $has('/scba (unit|pack)|air ?pack|facepiece|face piece|mask|helmet|bunker (coat|pants)|hood\b/') => new Cls(Cls::HIGH, 'ppe', 'support_services', isPpe: true, isCritical: true),
            $has('/cylinder|bottle/') => new Cls(Cls::MEDIUM, 'scba_cylinder', 'air_tech', isCritical: true),
            $has('/regulator|scba/') => new Cls(Cls::MEDIUM, 'scba_component', 'air_tech', isCritical: true),
            $has('/gas meter|multigas|four gas|detector/') => new Cls(Cls::MEDIUM, 'detection', 'support_services'),
            $has('/lifepak|\baed\b|defib/') => new Cls(Cls::MEDIUM, 'rescue_medical', 'captain5'),
            $has('/splint|backboard/') => new Cls(Cls::LOW, 'rescue_medical', 'captain5'),
            $has('/dewalt|m18|battery|batteries/') => new Cls(Cls::LOW, 'battery', 'support_services'),
            $has('/kitty litter|broom|spanner|hydrant wrench|tape|bucket|squeegee|door wedge/') => new Cls(Cls::LOW, 'consumable', 'support_services'),
            $has('/\bsaw\b|ipad|generator|k-tool|radio|nozzle|thermal/') => new Cls(Cls::MEDIUM, 'specialized_tool', 'support_services'),
            $has('/tire|brake|steering/') => new Cls(Cls::MEDIUM, 'vehicle_component', 'fleet'),
            $has('/\bac\b|air conditioning|refrigerator|chairs?\b/') => new Cls(Cls::LOW, 'station_facility', 'station'),
            default => new Cls(Cls::MEDIUM, 'other', 'support_services', reasons: ['unclassified_fallback'], unclassified: true),
        };
    }
}
