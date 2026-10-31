<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Support;

use App\Models\Apparatus;
use App\Services\DailyCheckoutChecklistResolver;
use Throwable;

/**
 * Dashboard filter tag and roster image for one apparatus, read only from structured fields.
 *
 * The primary source is the Daily Checkout checklist the unit resolves to
 * (DailyCheckoutChecklistResolver::checklistTypeFor: the approved template, else the exact
 * type/class_description family plus the unit identity), the same classification Captain 5 scope
 * and the inventory planner use. Only when that is unmapped does the exact `type` /
 * `class_description` value decide. Free text such as names or notes is never parsed.
 *
 *   checklist engine / engine2 -> engine_puc, image "engine"
 *   checklist ladder1          -> ladder,     image "ladder-1"
 *   checklist ladder3          -> ladder,     image "ladder-3"
 *   other ladders              -> ladder,     image "ladder-1" (generic ladder cutout)
 *   checklist rescue           -> rescue,     image "rescue"
 *   checklist fireboat6, or type "Marine" / "Fire Boat", or class "Fire Boats" -> marine; image "fireboat" for Fire Boat 6 only
 *   anything else (command, utility, unclassified) -> special, image null
 *
 * No apparatus field identifies the Command 300 or Captain 5 vehicles, so those cutouts are never
 * chosen here (the UI shows its fallback icon).
 */
final class ApparatusClass
{
    public const RESCUE = 'rescue';

    public const ENGINE = 'engine_puc';

    public const LADDER = 'ladder';

    public const MARINE = 'marine';

    public const SPECIAL = 'special';

    /** @var list<string> */
    public const TAGS = [self::RESCUE, self::ENGINE, self::LADDER, self::MARINE, self::SPECIAL];

    /** Columns the classifier reads (select these when eager-loading apparatus). */
    public const COLUMNS = ['id', 'unit_id', 'name', 'designation', 'type', 'class_description', 'daily_checkout_template'];

    public function __construct(private readonly DailyCheckoutChecklistResolver $resolver) {}

    /** @return array{tag: string, imageKey: string|null} */
    public function of(?Apparatus $apparatus): array
    {
        if (! $apparatus instanceof Apparatus) {
            return ['tag' => self::SPECIAL, 'imageKey' => null];
        }

        try {
            $checklist = $this->resolver->checklistTypeFor($apparatus);
        } catch (Throwable) {
            $checklist = 'unmapped';
        }

        $byChecklist = match ($checklist) {
            'engine', 'engine2' => ['tag' => self::ENGINE, 'imageKey' => 'engine'],
            'ladder1' => ['tag' => self::LADDER, 'imageKey' => 'ladder-1'],
            'ladder3' => ['tag' => self::LADDER, 'imageKey' => 'ladder-3'],
            'rescue' => ['tag' => self::RESCUE, 'imageKey' => 'rescue'],
            'fireboat6' => self::marine($apparatus),
            default => null,
        };
        if ($byChecklist !== null) {
            return $byChecklist;
        }

        $type = self::normalized($apparatus->type);
        $class = self::normalized($apparatus->class_description);

        return match (true) {
            in_array($type, ['marine', 'fire boat', 'fireboat'], true), $class === 'fire boats' => self::marine($apparatus),
            in_array($type, ['engine', 'engines', 'engine company'], true), $class === 'fire engines' => ['tag' => self::ENGINE, 'imageKey' => 'engine'],
            in_array($type, ['ladder', 'ladders', 'ladder company'], true), $class === 'fire ladders' => ['tag' => self::LADDER, 'imageKey' => 'ladder-1'],
            in_array($type, ['rescue', 'rescues', 'rescue company'], true), $class === 'fire rescues' => ['tag' => self::RESCUE, 'imageKey' => 'rescue'],
            default => ['tag' => self::SPECIAL, 'imageKey' => null],
        };
    }

    /**
     * The only boat art is Fire Boat 6, so any other boat keeps the fallback icon.
     *
     * @return array{tag: string, imageKey: string|null}
     */
    private static function marine(Apparatus $apparatus): array
    {
        $names = implode(' ', [(string) $apparatus->getAttribute('unit_id'), (string) $apparatus->name, (string) $apparatus->designation]);
        $isFireBoat6 = preg_match('/\b(fire\s*boat|fb|marine)[\s-]*6\b/i', $names) === 1;

        return ['tag' => self::MARINE, 'imageKey' => $isFireBoat6 ? 'fireboat' : null];
    }

    /**
     * Class of every apparatus, keyed by id. One query; the fleet is a few hundred rows at most.
     *
     * @return array<int, array{tag: string, imageKey: string|null}>
     */
    public function all(): array
    {
        $classes = [];
        foreach (Apparatus::query()->get(self::COLUMNS) as $apparatus) {
            $classes[(int) $apparatus->getKey()] = $this->of($apparatus);
        }

        return $classes;
    }

    /** Display name of the unit, as the Logistics board prints it. */
    public static function label(?Apparatus $apparatus): ?string
    {
        if (! $apparatus instanceof Apparatus) {
            return null;
        }
        $label = trim((string) ($apparatus->designation ?: $apparatus->name ?: $apparatus->getAttribute('unit_id')));

        return $label === '' ? null : $label;
    }

    private static function normalized(mixed $value): string
    {
        return is_string($value) ? strtolower(trim((string) preg_replace('/\s+/', ' ', $value))) : '';
    }
}
