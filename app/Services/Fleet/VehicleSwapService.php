<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\Station;
use Illuminate\Support\Str;

/**
 * Moves a unit's identity between two truck rows, for ApparatusChangeoutService. An apparatus row is the physical truck:
 * its vehicle number, VIN, meters, defects, inspections and tickets never move.
 */
final class VehicleSwapService
{
    public const CHANGEOUT_NEW_TRUCK_STATUS = 'In Service';

    /** What makes a row the unit: its identity, station and frontline role. Never the truck's own attributes. */
    private const IDENTITY_FIELDS = ['unit_id', 'designation', 'name', 'slug', 'station_id', 'assignment', 'daily_checkout_requirement', 'daily_checkout_template'];

    /**
     * Move a unit to another truck for a changeout. The caller holds the transaction and has locked both rows in id order.
     * The new truck takes the unit's identity, and is In Service. The old truck takes the new truck's previous identity,
     * and is Out of Service. That is every swap, including a return, whatever either truck was before: the crew said so.
     * A truck holding another frontline unit trades identities, so that unit is now listed on the old truck, at its own
     * station.
     *
     * Both trucks are physically where the unit is, until Fleet moves the old one. A number-derived reserve identity
     * (R-1033, RESCUE 1033, r1033, reserve-<id>) is rewritten for the truck that now carries it, so a label never names
     * another truck. vehicle_number, VIN, meters, defects, inspections and tickets never move.
     *
     * @return array{before: array{from: array<string, mixed>, to: array<string, mixed>}, after: array{from: array<string, mixed>, to: array<string, mixed>}}
     */
    public function moveUnitForChangeout(Apparatus $from, Apparatus $to): array
    {
        $before = ['from' => $this->snapshot($from), 'to' => $this->snapshot($to)];
        $identity = static fn (Apparatus $row): array => collect(self::IDENTITY_FIELDS)
            ->mapWithKeys(static fn (string $field): array => [$field => $row->getRawOriginal($field)])->all();
        $unitIdentity = $identity($from);
        $vacated = $identity($to);
        $location = $this->locationOf($from);
        $traded = ! ApparatusChangeoutPlanner::isReserveTruck($to);

        // A traded frontline unit keeps its own name and its own station. Only a spare's number-derived label moves.
        if (! $traded) {
            foreach (['unit_id', 'designation', 'name', 'slug'] as $field) {
                $vacated[$field] = $this->rewrittenForTruck($vacated[$field], $field, $to, $from);
            }
        }
        foreach (['unit_id', 'slug'] as $field) {
            $vacated[$field] = $this->freeFor($field, $vacated[$field], $from, $to);
        }

        $from->forceFill(['unit_id' => 'swap-hold-'.$from->getKey(), 'slug' => 'swap-hold-'.$from->getKey()])->save();
        $to->forceFill([...$unitIdentity, 'status' => self::CHANGEOUT_NEW_TRUCK_STATUS, 'current_location' => $location])->save();
        $from->forceFill([
            ...$vacated,
            'status' => ApparatusChangeout::OLD_TRUCK_STATUS,
            'station_id' => $traded ? $vacated['station_id'] : $from->station_id,
            'current_location' => $location,
        ])->save();

        return ['before' => $before, 'after' => ['from' => $this->snapshot($from->refresh()), 'to' => $this->snapshot($to->refresh())]];
    }

    /** The station the unit is at, in the words the location field uses. A unit with no station keeps what it had. */
    private function locationOf(Apparatus $unit): mixed
    {
        $number = $unit->station_id === null ? null : Station::query()->whereKey($unit->station_id)->value('station_number');

        return $number === null ? $unit->getAttribute('current_location') : 'Station '.$number;
    }

    /** unit_id and slug are unique: a rewritten label can land on one another row still carries. */
    private function freeFor(string $column, mixed $value, Apparatus $from, Apparatus $to): mixed
    {
        $taken = is_string($value) && Apparatus::query()->where($column, $value)->whereKeyNot([$from->getKey(), $to->getKey()])->exists();

        return $taken ? $value.'-'.$from->getKey() : $value;
    }

    /**
     * A label that names the truck it was on names the truck that now carries it. Whole numbers only: 1033 is not
     * inside R-11033. An older reserve identity is named by row id, so it follows the row the same way.
     */
    private function rewrittenForTruck(mixed $value, string $field, Apparatus $previous, Apparatus $next): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        if (in_array($field, ['unit_id', 'slug'], true) && $value === 'reserve-'.$previous->getKey()) {
            return 'reserve-'.$next->getKey();
        }
        if (blank($previous->vehicle_number) || blank($next->vehicle_number)) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/(?<![0-9])'.preg_quote((string) $previous->vehicle_number, '/').'(?![0-9])/iu',
            static fn (): string => $field === 'slug' ? Str::slug((string) $next->vehicle_number) : (string) $next->vehicle_number,
            $value,
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(Apparatus $truck): array
    {
        return [
            'id' => $truck->getKey(),
            'designation' => $truck->designation,
            'unit_id' => $truck->getAttribute('unit_id'),
            'station_id' => $truck->station_id,
            'status' => $truck->getAttribute('status'),
            'vehicle_number' => $truck->vehicle_number,
            'vin' => $truck->getAttribute('vin'),
            'current_miles' => $truck->current_miles,
            'current_engine_hours' => $truck->current_engine_hours,
        ];
    }
}
