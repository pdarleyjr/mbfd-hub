<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Support\Fleet\FrontlineUnits;
use App\Support\Fleet\UnitIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a unit (E1) and a truck (vehicle 20503) apart on every apparatus write, not only the admin form. Runs from the
 * Apparatus saving hook, and only looks at what the save changes, so an older row is never blocked on an unrelated edit.
 *
 * Refused, for every truck:
 *  - a unit ID or designation that is another truck's vehicle number (the Nov 5 mistake);
 *  - a vehicle number that is another row's unit ID or designation.
 *
 * Refused for the approved front-line units (config/daily_checkout_frontline.php), the same invariant the
 * daily-checkout:apply-approved-policy deploy gate checks:
 *  - a front-line unit named on two truck records (unit ID, designation or name);
 *  - a front-line unit ID whose designation names a different unit (unit ID E1 with designation "E 11");
 *  - a designation or name that names a front-line unit while the unit ID names a different front-line unit.
 *
 * Free-text names and spare labels ("Reserve", "Rescue 17503", "R 44 Replacement") are left alone: they are history,
 * not identity. A truck swap moves identities through short in-between states, so VehicleSwapService runs inside
 * whileMovingUnits(). That is the only way past the guard.
 */
final class UnitIdentityGuard
{
    private const WATCHED = [...UnitIdentity::FIELDS, 'vehicle_number'];

    private const LABELS = ['unit_id' => 'Unit ID', 'designation' => 'Designation', 'name' => 'Name'];

    private static int $movingUnits = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileMovingUnits(callable $callback): mixed
    {
        self::$movingUnits++;
        try {
            return $callback();
        } finally {
            self::$movingUnits--;
        }
    }

    /** @throws ValidationException */
    public function check(Apparatus $row): void
    {
        if (self::$movingUnits > 0) {
            return;
        }
        $problems = $this->problems($row);
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * What is wrong with the identity this save would write, by field. Empty when nothing watched changed.
     *
     * @return array<string, string>
     */
    public function problems(Apparatus $row): array
    {
        $changed = array_values(array_filter(self::WATCHED, static fn (string $field): bool => $row->isDirty($field)));
        if ($changed === []) {
            return [];
        }

        $others = Apparatus::query()
            ->when($row->exists, static fn ($query) => $query->whereKeyNot($row->getKey()))
            ->get(['id', 'unit_id', 'designation', 'name', 'vehicle_number']);

        $problems = [];
        foreach (['unit_id', 'designation'] as $field) {
            if ($row->isDirty($field)) {
                $problems[$field] = $this->vehicleNumberAsUnit($row, $field, $others);
            }
        }
        if ($row->isDirty('vehicle_number')) {
            $problems['vehicle_number'] = $this->unitAsVehicleNumber($row, $others);
        }
        foreach ($this->frontlineProblems($row, $others) as $field => $message) {
            $problems[$field] ??= $message;
        }

        return array_filter($problems, static fn (?string $message): bool => $message !== null);
    }

    /** @param Collection<int, Apparatus> $others */
    private function vehicleNumberAsUnit(Apparatus $row, string $field, Collection $others): ?string
    {
        $value = (string) $row->getAttribute($field);
        $key = UnitIdentity::key($value);
        if ($key === '' || ($field === 'designation' && UnitIdentity::isSharedLabel($value))) {
            return null;
        }
        $truck = $others->first(static fn (Apparatus $other): bool => UnitIdentity::key($other->vehicle_number) === $key);

        return $truck === null ? null
            : self::LABELS[$field]." {$value} is the vehicle number of another truck (".self::describe($truck).'). A unit ID and designation name the unit, like E1 and E 1, never a truck. To put a different truck on this unit, use Swap truck.';
    }

    /** @param Collection<int, Apparatus> $others */
    private function unitAsVehicleNumber(Apparatus $row, Collection $others): ?string
    {
        $value = (string) $row->vehicle_number;
        $key = UnitIdentity::key($value);
        if ($key === '') {
            return null;
        }
        $unit = $others->first(static fn (Apparatus $other): bool => UnitIdentity::key($other->getAttribute('unit_id')) === $key
            || (! UnitIdentity::isSharedLabel($other->designation) && UnitIdentity::key($other->designation) === $key));

        return $unit === null ? null
            : "Vehicle # {$value} is the name of a unit on another truck (".self::describe($unit).'). Enter the number shown on this truck.';
    }

    /**
     * @param  Collection<int, Apparatus>  $others
     * @return array<string, string>
     */
    private function frontlineProblems(Apparatus $row, Collection $others): array
    {
        if (! $row->isDirty('unit_id') && ! $row->isDirty('designation') && ! $row->isDirty('name')) {
            return [];
        }
        $unitId = (string) $row->getAttribute('unit_id');
        $ownUnit = self::frontlineUnitNamedBy($unitId);
        $problems = [];

        if ($ownUnit !== null) {
            $holder = $others->first(static fn (Apparatus $other): bool => FrontlineCoverage::matches([$other], $ownUnit)->isNotEmpty());
            if ($holder !== null && ($row->isDirty('unit_id') || $row->isDirty('designation') || $row->isDirty('name'))) {
                $problems['unit_id'] = "{$ownUnit} is already on another truck (".self::describe($holder).'). A unit can be on one truck at a time. To move it to this truck, use Swap truck.';
            }
            // "E 1", "Engine 1" and "Fire Boat 6" all fit unit E1 or FB6; "E 11" or "R 1" on unit E1 does not.
            $designation = $row->designation;
            $number = UnitIdentity::number($designation);
            if (is_string($designation) && $number !== null && ! UnitIdentity::isSharedLabel($designation)
                && ($number !== UnitIdentity::number($ownUnit) || UnitIdentity::letter($designation) !== UnitIdentity::letter($ownUnit))) {
                $problems['designation'] = "Designation {$designation} does not match unit ID {$unitId}. Use ".UnitIdentity::designationFor($ownUnit).', or change both together.';
            }
        }

        foreach (['designation', 'name'] as $field) {
            $value = $row->getAttribute($field);
            $named = is_string($value) ? self::frontlineUnitNamedBy($value) : null;
            if ($named === null || $named === $ownUnit || (! $row->isDirty($field) && ! $row->isDirty('unit_id'))) {
                continue;
            }
            if ($ownUnit !== null) {
                $problems[$field] ??= self::LABELS[$field]." {$value} names {$named}, but this truck's unit ID is {$unitId}. Change both together.";

                continue;
            }
            $holder = $others->first(static fn (Apparatus $other): bool => in_array(UnitIdentity::key($other->getAttribute('unit_id')), FrontlineUnits::aliasKeys($named), true));
            if ($holder !== null) {
                $problems[$field] ??= self::LABELS[$field]." {$value} names {$named}, which is on another truck (".self::describe($holder).'). To move it to this truck, use Swap truck.';
            }
        }

        return $problems;
    }

    /** The approved front-line unit a unit ID, designation or name spells, or null. */
    private static function frontlineUnitNamedBy(string $value): ?string
    {
        $key = UnitIdentity::key($value);
        if ($key === '') {
            return null;
        }
        foreach (FrontlineUnits::unitIds() as $unitId) {
            if (in_array($key, FrontlineUnits::aliasKeys($unitId), true)) {
                return $unitId;
            }
        }

        return null;
    }

    private static function describe(Apparatus $truck): string
    {
        $unit = $truck->designation ?: $truck->name ?: $truck->getAttribute('unit_id');
        $vehicle = filled($truck->vehicle_number) ? 'vehicle '.$truck->vehicle_number : 'no vehicle number';

        return trim(($unit ? $unit.', ' : '').$vehicle.', record #'.$truck->getKey());
    }
}
