<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use App\Services\Fleet\UnitMasterVehicleChangeRecorder;
use App\Support\Fleet\MasterVehicleSyncGuard;

/**
 * Master vehicle side of the apparatus <-> master vehicle sync.
 *
 * Two jobs on every update, whoever wrote it (Filament, import, recorder):
 *  - history: a change to a recorded field made outside the change recorder is logged as
 *    source "master_inventory" with the signed-in user. Recorder writes log themselves.
 *  - propagation: shared fields are carried to the linked apparatus copy.
 * Writes made by the sync itself are ignored (MasterVehicleSyncGuard).
 */
final class UnitMasterVehicleSyncObserver
{
    public function __construct(
        private readonly ApparatusMasterVehicleLink $link,
        private readonly UnitMasterVehicleChangeRecorder $recorder,
    ) {}

    public function updated(UnitMasterVehicle $vehicle): void
    {
        if (MasterVehicleSyncGuard::isPropagating()) {
            return;
        }
        $changed = array_values(array_filter(
            UnitMasterVehicleChangeRecorder::FIELDS,
            static fn (string $field): bool => $vehicle->wasChanged($field),
        ));
        if ($changed === []) {
            return;
        }

        if (! MasterVehicleSyncGuard::isRecording()) {
            $actor = auth()->user();
            foreach ($changed as $field) {
                $this->recorder->recordApplied(
                    $vehicle,
                    $field,
                    $this->text($vehicle->getOriginal($field)),
                    $this->text($vehicle->getAttribute($field)),
                    UnitMasterVehicleChange::SOURCE_MASTER_INVENTORY,
                    ['user' => $actor instanceof User ? $actor : null],
                );
            }
        }

        $this->link->propagateMasterToApparatus($vehicle, $changed);
    }

    public function deleting(UnitMasterVehicle $vehicle): void
    {
        $this->link->masterRemoved($vehicle);
    }

    private function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
