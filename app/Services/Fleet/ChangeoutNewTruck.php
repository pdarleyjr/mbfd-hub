<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Support\Fleet\MasterVehicleSyncGuard;
use App\Support\Fleet\VehicleIdentity;
use Illuminate\Support\Str;

/**
 * A crew typed a number no apparatus row carries. They are standing at that truck, so it exists: the row is made here, as
 * a reserve truck of the unit's type, and Fleet is told to verify it. Master vehicle numbers are not unique, so anything
 * but one clean match is left unlinked with a plain note. The caller holds the transaction.
 */
final class ChangeoutNewTruck
{
    private const INITIAL_STATUS = 'Available';

    public function __construct(
        private readonly ApparatusMasterVehicleLink $link,
        private readonly UnitMasterVehicleChangeRecorder $recorder,
    ) {}

    /** @return array{truck: Apparatus, review_note: string} */
    public function create(string $number, Apparatus $unit, User $actor): array
    {
        $probe = new Apparatus;
        $probe->vehicle_number = $number;
        $resolution = $this->link->resolve($probe);
        $master = $resolution->isLinked() ? $resolution->master : null;
        if ($master instanceof UnitMasterVehicle && $master->isMarkedNotApparatus()) {
            $master = null;
        }

        if ($master instanceof UnitMasterVehicle) {
            $truck = $this->row($number, $unit, $master);
            $this->link->link($truck, $master, $actor, UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT);

            return ['truck' => $truck->refresh(), 'review_note' => "Truck {$number} had a Master Vehicle Inventory record but no apparatus record, so one was made from it. Please review."];
        }

        if ($resolution->code === MasterLinkResolution::CODE_NOT_FOUND) {
            $master = $this->newMaster($number, $actor);
            $truck = $this->row($number, $unit, $master);
            $this->link->link($truck, $master, $actor, UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT);

            return ['truck' => $truck->refresh(), 'review_note' => "Truck {$number} was not in the fleet records. New records were made from what the crew entered. Please verify them."];
        }

        $reason = $resolution->reason ?? "Vehicle record {$number} is marked as not an apparatus.";
        $truck = $this->row($number, $unit, null);
        $this->link->markNeedsReview($truck, "A crew changed onto truck {$number}. {$reason}");

        return ['truck' => $truck->refresh(), 'review_note' => "Truck {$number} had no apparatus record, so one was made. {$reason} Please link the right vehicle record."];
    }

    private function newMaster(string $number, User $actor): UnitMasterVehicle
    {
        $master = MasterVehicleSyncGuard::propagating(fn (): UnitMasterVehicle => UnitMasterVehicle::query()->create([
            'veh_number' => $number,
            'asset_category' => UnitMasterVehicle::CATEGORY_VEHICLE,
            'needs_fleet_review' => true,
            'record_source' => UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT,
        ]));
        $this->recorder->recordCreated($master, UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, [
            'user' => $actor,
            'note' => "Created when {$actor->name} changed trucks onto {$number}, pending Fleet verification",
        ]);

        return $master;
    }

    private function row(string $number, Apparatus $unit, ?UnitMasterVehicle $master): Apparatus
    {
        $type = trim((string) $unit->type);

        // The link below is the one that counts; the apparatus observers stay out of the way.
        return MasterVehicleSyncGuard::propagating(fn (): Apparatus => Apparatus::query()->create([
            'designation' => 'Reserve',
            'unit_id' => $this->free('unit_id', 'R-'.$number),
            'slug' => $this->free('slug', 'r'.Str::slug($number)),
            'name' => mb_strtoupper($type).' '.$number,
            'type' => $type,
            'class_description' => VehicleIdentity::text($master->fleet_class_description ?? $unit->class_description),
            'station_id' => $unit->station_id,
            'status' => self::INITIAL_STATUS,
            'daily_checkout_requirement' => DailyCheckoutRequirement::Reserve,
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::Pending,
            'vehicle_number' => $number,
            'year' => VehicleIdentity::year($master?->year),
            'make' => VehicleIdentity::text($master?->make),
            'model' => VehicleIdentity::text($master?->model),
            'vin' => VehicleIdentity::text($master?->serial_number),
        ]));
    }

    /** unit_id and slug are unique, and an old reserve label may still be sitting on another row. */
    private function free(string $column, string $value): string
    {
        $candidate = $value;
        for ($suffix = 2; Apparatus::query()->where($column, $candidate)->exists(); $suffix++) {
            $candidate = $value.'-'.$suffix;
        }

        return $candidate;
    }
}
