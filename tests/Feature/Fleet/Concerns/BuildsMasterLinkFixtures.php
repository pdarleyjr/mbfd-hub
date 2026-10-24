<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Concerns;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Master vehicle and apparatus rows shaped like production. VINs are synthetic.
 */
trait BuildsMasterLinkFixtures
{
    /** @param array<string, mixed> $attributes */
    protected function master(string $number, array $attributes = []): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create(array_merge([
            'veh_number' => $number,
            'section' => 'Frontline and Reserve Fire Apparatus',
            'asset_category' => 'vehicle',
        ], $attributes));
    }

    /**
     * An apparatus row as production holds it today: saved without model events, so it
     * is not linked yet. Use this to test linking and reconciliation of existing rows.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function legacyApparatus(string $designation, ?string $vehicleNumber, array $attributes = []): Apparatus
    {
        return Apparatus::withoutEvents(fn (): Apparatus => Apparatus::query()->create(array_merge([
            'designation' => $designation,
            'unit_id' => $designation,
            'name' => $designation,
            'slug' => Str::slug($designation),
            'type' => 'Engine',
            'vehicle_number' => $vehicleNumber,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
        ], $attributes)));
    }

    /**
     * An apparatus created the normal way, so the sync reacts to it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function liveApparatus(string $designation, ?string $vehicleNumber, array $attributes = []): Apparatus
    {
        return Apparatus::query()->create(array_merge([
            'designation' => $designation,
            'unit_id' => $designation,
            'name' => $designation,
            'type' => 'Engine',
            'vehicle_number' => $vehicleNumber,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
        ], $attributes));
    }

    /** @return Collection<int, UnitMasterVehicleChange> */
    protected function changesFor(UnitMasterVehicle $master, ?string $source = null): Collection
    {
        return UnitMasterVehicleChange::query()
            ->where('unit_master_vehicle_id', $master->getKey())
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->orderBy('id')
            ->get();
    }
}
