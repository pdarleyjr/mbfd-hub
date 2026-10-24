<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Apparatus;
use App\Models\User;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use App\Support\Fleet\MasterVehicleSyncGuard;

/**
 * Apparatus side of the apparatus <-> master vehicle sync.
 *
 * Must be registered BEFORE ApparatusObserver: that observer re-saves the row inside its
 * own "created" and "updated" handlers, which would replace the change tracking this
 * observer reads. Writes made by the sync itself are ignored (MasterVehicleSyncGuard).
 */
final class ApparatusMasterLinkObserver
{
    public function __construct(private readonly ApparatusMasterVehicleLink $link) {}

    public function created(Apparatus $apparatus): void
    {
        if (MasterVehicleSyncGuard::isPropagating()) {
            return;
        }
        $this->link->reconcileInserted($apparatus, $this->actor());
    }

    public function updated(Apparatus $apparatus): void
    {
        if (MasterVehicleSyncGuard::isPropagating()) {
            return;
        }

        // The vehicle number is the physical truck's key. Changing it never renames the
        // master record; it moves the link to whichever master has the new number.
        if ($apparatus->wasChanged('vehicle_number')) {
            $this->link->relinkAfterNumberChange($apparatus, $this->actor());

            return;
        }

        if ($apparatus->unit_master_vehicle_id === null || $apparatus->master_link_status !== Apparatus::MASTER_LINK_LINKED) {
            return;
        }
        $changed = [];
        foreach (ApparatusMasterVehicleLink::APPARATUS_EDITABLE_FIELDS as $masterField => $apparatusField) {
            if ($apparatus->wasChanged($apparatusField)) {
                $changed[] = $masterField;
            }
        }
        if ($changed !== []) {
            $this->link->pushApparatusEditsToMaster($apparatus, $changed, $this->actor());
        }
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
