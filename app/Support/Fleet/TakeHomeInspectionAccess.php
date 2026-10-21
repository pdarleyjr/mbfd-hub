<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Models\User;

/**
 * Who may see Take Home Vehicle Inspections.
 *
 * Every active Hub account may create inspections and see its own. Only Fleet
 * viewers (super_admin, or admin.access plus admin.fleet.view/manage) see all
 * inspections and vehicle profiles. Selecting someone as the driver never grants
 * access to that person's history: access follows the inspecting account only.
 *
 * These are explicit checks rather than model-policy abilities so the admin
 * capability gate (which maps model abilities to admin.* permissions) never
 * hides a member's own submission from them.
 */
final class TakeHomeInspectionAccess
{
    public static function canViewAll(?User $user): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasDirectWebPermission('admin.access')
            && ($user->hasDirectWebPermission('admin.fleet.view') || $user->hasDirectWebPermission('admin.fleet.manage'));
    }

    public static function canView(?User $user, TakeHomeVehicleInspection $inspection): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        return (int) $inspection->inspector_user_id === (int) $user->getKey() || self::canViewAll($user);
    }

    public static function canViewPhoto(?User $user, TakeHomeVehicleInspectionPhoto $photo): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        if ($photo->take_home_vehicle_inspection_id === null) {
            // Not yet submitted: only the person who uploaded it.
            return (int) $photo->uploaded_by_user_id === (int) $user->getKey();
        }

        return self::canView($user, $photo->inspection);
    }
}
