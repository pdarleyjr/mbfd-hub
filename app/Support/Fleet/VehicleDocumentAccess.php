<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;

/** Explicit checks keep document sensitivity outside the broad admin model gate. */
final class VehicleDocumentAccess
{
    public static function canViewVehicle(?User $user, ?UnitMasterVehicle $vehicle = null): bool
    {
        return self::active($user) && ($user->hasRole('super_admin') || (
            $user->hasDirectWebPermission('admin.access')
            && ($user->hasDirectWebPermission('admin.fleet.view') || $user->hasDirectWebPermission('admin.fleet.manage'))
        ));
    }

    public static function canManage(?User $user, ?UnitMasterVehicle $vehicle = null): bool
    {
        return self::active($user) && ($user->hasRole('super_admin') || (
            $user->hasDirectWebPermission('admin.access') && $user->hasDirectWebPermission('admin.fleet.manage')
        ));
    }

    public static function canSensitive(?User $user): bool
    {
        return self::canViewVehicle($user) && ($user->hasRole('super_admin') || $user->hasPermissionTo('admin.fleet.documents.sensitive', 'web'));
    }

    public static function canExport(?User $user, UnitMasterVehicle $vehicle): bool
    {
        return self::canViewVehicle($user, $vehicle) && ($user->hasRole('super_admin') || $user->hasPermissionTo('admin.fleet.documents.export', 'web'));
    }

    public static function canViewDocument(?User $user, VehicleDocument $document, ?UnitMasterVehicle $vehicle = null): bool
    {
        if (! self::canViewVehicle($user, $vehicle) || ($document->is_sensitive && ! self::canSensitive($user))) {
            return false;
        }

        if ($vehicle !== null) {
            return $document->vehicles()->whereKey($vehicle->getKey())->exists();
        }

        // Only managers may examine the unlinked historical-import review queue.
        return self::canManage($user) || $document->vehicles()->exists();
    }

    public static function canManageDocument(?User $user, VehicleDocument $document): bool
    {
        return self::canManage($user) && self::canViewDocument($user, $document);
    }

    private static function active(?User $user): bool
    {
        return $user instanceof User && $user->isAuthenticationAllowed();
    }
}
