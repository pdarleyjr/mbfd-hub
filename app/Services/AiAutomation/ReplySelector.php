<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Services\SupportTriage\EquipmentClassification;

/**
 * Pure mapping from one catalog result (level, handler, category, hazard) to the Playbooks template a member
 * is told. No database, no model, no text analysis beyond the flags it is given. The catalog is the only
 * opinion about importance; this class only picks the words.
 *
 * Contexts: "item" = a vehicle checkout finding, "station" = a station or supply request, "message" = a
 * Support Services or issue-thread message.
 */
final class ReplySelector
{
    public const PLAIN = 'plain';

    public const CUSTOM = 'catalog_response';

    /**
     * @param  'low'|'medium'|'high'  $level  the effective level (the triage projection when staff changed it)
     * @param  'item'|'station'|'message'  $context
     * @param  'missing'|'damaged'|'other'  $condition
     */
    public function choose(
        EquipmentClassification $cls,
        string $level,
        string $context,
        string $condition = 'other',
        bool $leak = false,
        bool $dewalt = false,
    ): ReplyChoice {
        $hazard = $cls->hazard && $level === EquipmentClassification::HIGH;
        $reasons = array_values(array_unique(array_filter([
            'level_'.$level,
            'handler_'.$cls->handler,
            'category_'.$cls->category,
            $cls->unclassified ? 'unclassified' : null,
            $hazard ? 'hazard' : null,
            ...$cls->reasons,
        ])));
        $air = $cls->handler === 'air_tech';
        $leakingCylinder = $air && $leak && $cls->category === 'scba_cylinder';

        $key = match (true) {
            trim((string) $cls->memberResponse) !== '' => self::CUSTOM,
            $leakingCylinder => 'medium_cylinder_leak',
            $hazard => $context === 'station' ? 'hazard_station' : 'readiness',
            $level === EquipmentClassification::HIGH => $cls->isPpe ? 'high_ppe' : ($context === 'station' ? 'hazard_station' : 'readiness'),
            $air => 'medium_air_tech',
            $cls->handler === 'captain5' => $level === EquipmentClassification::LOW ? 'captain5_low' : 'captain5_medium',
            $cls->handler === 'officer_300' => 'readiness',
            in_array($cls->handler, ['fleet', 'station'], true) => self::PLAIN,
            default => $this->supportServicesKey($cls, $level, $context, $condition, $dewalt),
        };

        $tier = match (true) {
            $hazard, $level === EquipmentClassification::HIGH => 'critical',
            $level === EquipmentClassification::MEDIUM && $this->escalates($cls, $context, $leakingCylinder) => 'elevated',
            default => 'routine',
        };

        return new ReplyChoice(
            $key,
            $tier,
            $cls->handler === 'support_services',
            $reasons,
        );
    }

    private function supportServicesKey(EquipmentClassification $cls, string $level, string $context, string $condition, bool $dewalt): string
    {
        if ($context !== 'item') {
            // Free text and requests name no checklist item, so only the detection-device words are specific.
            return $level === EquipmentClassification::MEDIUM && $cls->category === 'detection' ? 'medium_detection' : self::PLAIN;
        }

        if ($level === EquipmentClassification::LOW) {
            if ($condition !== 'missing') {
                return 'damaged_low';
            }

            return $cls->category === 'battery' ? ($dewalt ? 'missing_battery_dewalt' : 'missing_battery') : 'missing_low';
        }

        return $cls->category === 'detection' ? 'medium_detection' : 'medium_tool';
    }

    /** MEDIUM stays routine only for plain Support Services items; anything handed to another team is watched. */
    private function escalates(EquipmentClassification $cls, string $context, bool $leakingCylinder): bool
    {
        return $cls->handler !== 'support_services'
            || $cls->isCritical
            || $leakingCylinder
            // An unknown piece of equipment is worth a look; unknown free text is just chat.
            || ($cls->unclassified && $context === 'item');
    }
}
