<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

/**
 * The one answer to "how important is this?" for an item, station request or message. Dashboards,
 * notifications and the reply engine all use it, so there is never a second opinion elsewhere.
 */
final readonly class EquipmentClassification
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const LEVELS = [self::LOW, self::MEDIUM, self::HIGH];

    /** Categories (plain-language labels live in SupportTriageLabels). */
    public const CATEGORIES = [
        'consumable', 'hand_tool', 'battery', 'power_tool', 'specialized_tool', 'detection', 'scba_unit',
        'scba_facepiece', 'scba_component', 'scba_cylinder', 'ppe', 'rescue_medical', 'vehicle_component',
        'station_facility', 'electronics', 'other',
    ];

    /** Who handles replacement or follow-up. */
    public const HANDLERS = ['support_services', 'air_tech', 'captain5', 'fleet', 'station', 'officer_300'];

    /**
     * @param  'low'|'medium'|'high'  $level
     * @param  list<string>  $reasons  machine codes, e.g. catalog_match, hazard_override, significant_flag, unclassified_fallback
     */
    public function __construct(
        public string $level,
        public string $category,
        public string $handler,
        public ?int $ruleId = null,
        public ?string $ruleName = null,
        public array $reasons = [],
        public ?string $memberResponse = null,
        public bool $isPpe = false,
        public bool $isCritical = false,
        public bool $hazard = false,
        public bool $unclassified = false,
        public string $contact300 = 'urgent',
    ) {}

    public static function rank(string $level): int
    {
        return match ($level) {
            self::HIGH => 3,
            self::MEDIUM => 2,
            default => 1,
        };
    }

    /** Highest level of several (a checkout with mixed findings), each item keeping its own result. */
    public static function highest(string ...$levels): string
    {
        $best = self::LOW;
        foreach ($levels as $level) {
            if (self::rank($level) > self::rank($best)) {
                $best = $level;
            }
        }

        return $best;
    }
}
