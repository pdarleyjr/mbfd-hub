<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TakeHomeVehicleInspection;
use App\Models\User;
use App\Support\Fleet\TakeHomeInspectionAccess;

/**
 * Admin-panel access to Take Home Vehicle Inspections is read-only and limited
 * to Fleet viewers. Inspections are immutable, so nothing else is ever allowed.
 */
class TakeHomeVehicleInspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return TakeHomeInspectionAccess::canViewAll($user);
    }

    public function view(User $user, TakeHomeVehicleInspection $inspection): bool
    {
        return TakeHomeInspectionAccess::canViewAll($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TakeHomeVehicleInspection $inspection): bool
    {
        return false;
    }

    public function delete(User $user, TakeHomeVehicleInspection $inspection): bool
    {
        return false;
    }

    public function restore(User $user, TakeHomeVehicleInspection $inspection): bool
    {
        return false;
    }

    public function forceDelete(User $user, TakeHomeVehicleInspection $inspection): bool
    {
        return false;
    }
}
