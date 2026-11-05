<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Support\Fleet\FrontlineUnits;
use App\Support\Fleet\UnitIdentity;
use Illuminate\Support\Collection;

/**
 * Is every front-line unit on exactly one truck, and is that truck In Service? Read only. The Apparatus pages show the
 * answer as a warning, and daily-checkout:apply-approved-policy uses the same wording when it refuses, so a deploy gate
 * and the page say the same thing.
 */
final class FrontlineCoverage
{
    public const KIND_SEVERAL = 'several_trucks';

    public const KIND_CONFLICT = 'conflicting_identity';

    public const KIND_MISSING = 'no_truck';

    public const KIND_NOT_IN_SERVICE = 'truck_not_in_service';

    /**
     * One line per front-line unit that needs Fleet. Units missing entirely are only reported once the fleet has at least
     * one front-line unit, so an empty or test database is not a wall of warnings. Fire Boat 6 is made by the policy
     * command when it is missing, so it is never reported missing.
     *
     * @return list<array{unit_id: string, kind: string, apparatus_id: int|null, message: string}>
     */
    public function problems(): array
    {
        $trucks = Apparatus::query()->get(['id', 'unit_id', 'designation', 'name', 'vehicle_number', 'status']);
        $problems = [];
        $anyPresent = false;
        foreach (FrontlineUnits::unitIds() as $unitId) {
            $matches = self::matches($trucks, $unitId);
            if ($matches->count() > 1) {
                $problems[] = $this->problem($unitId, self::KIND_SEVERAL, null, self::severalMessage($unitId, $matches->all()));
                $anyPresent = true;

                continue;
            }
            $truck = $matches->first();
            if ($truck === null) {
                if ($unitId !== 'FB6') {
                    $problems[] = $this->problem($unitId, self::KIND_MISSING, null, self::missingMessage($unitId));
                }

                continue;
            }
            $anyPresent = true;
            if (UnitIdentity::key($truck->getAttribute('unit_id')) !== UnitIdentity::key($unitId)) {
                $problems[] = $this->problem($unitId, self::KIND_CONFLICT, (int) $truck->getKey(), self::conflictMessage($unitId, $truck));
            } elseif ($truck->getAttribute('status') !== 'In Service') {
                $problems[] = $this->problem($unitId, self::KIND_NOT_IN_SERVICE, (int) $truck->getKey(), self::notInServiceMessage($unitId, $truck));
            }
        }

        return $anyPresent ? $problems : [];
    }

    /**
     * Rows that name the unit in their unit ID, designation or name.
     *
     * @param  iterable<Apparatus>  $trucks
     * @return Collection<int, Apparatus>
     */
    public static function matches(iterable $trucks, string $unitId): Collection
    {
        $keys = FrontlineUnits::aliasKeys($unitId);

        return collect($trucks)->filter(static function (Apparatus $truck) use ($keys): bool {
            foreach ([$truck->getAttribute('unit_id'), $truck->designation, $truck->name] as $value) {
                if (in_array(UnitIdentity::key($value), $keys, true)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** @param list<Apparatus> $trucks */
    public static function severalMessage(string $unitId, array $trucks): string
    {
        $list = implode(' and ', array_map(static fn (Apparatus $truck): string => self::truckLabel($truck), $trucks));

        return "{$unitId} is named on more than one truck record: {$list}. A unit can be on one truck at a time. Open the record that should not carry {$unitId} and use Change unit assignment.";
    }

    public static function conflictMessage(string $unitId, Apparatus $truck): string
    {
        $actual = (string) ($truck->getAttribute('unit_id') ?: 'blank');

        return "{$unitId} appears as the designation or name of ".self::truckLabel($truck).", but that record's unit ID is {$actual}. Open it and use Change unit assignment so every unit field names the same unit.";
    }

    public static function missingMessage(string $unitId): string
    {
        return "No truck record carries {$unitId}. Use Swap truck to put a truck on {$unitId}, or Change unit assignment on the truck that runs as {$unitId}.";
    }

    public static function notInServiceMessage(string $unitId, Apparatus $truck): string
    {
        $status = (string) ($truck->getAttribute('status') ?: 'not set');
        $number = filled($truck->vehicle_number) ? 'truck '.$truck->vehicle_number : 'a truck with no vehicle number';

        return "{$unitId} is on {$number}, which is {$status}. Use Swap truck on {$unitId} to put an In Service truck on it.";
    }

    public static function stationMessage(string $unitId, int $approvedStation, Apparatus $truck): string
    {
        return "{$unitId} is approved for Station {$approvedStation}, but ".self::truckLabel($truck).' is assigned to a different station. Fix the station under More settings on that record.';
    }

    public static function truckLabel(Apparatus $truck): string
    {
        $vehicle = filled($truck->vehicle_number) ? 'vehicle '.$truck->vehicle_number : 'no vehicle number';

        return "record #{$truck->getKey()} ({$vehicle})";
    }

    /** @return array{unit_id: string, kind: string, apparatus_id: int|null, message: string} */
    private function problem(string $unitId, string $kind, ?int $apparatusId, string $message): array
    {
        return ['unit_id' => $unitId, 'kind' => $kind, 'apparatus_id' => $apparatusId, 'message' => $message];
    }
}
