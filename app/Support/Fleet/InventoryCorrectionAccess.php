<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionParticipant;
use App\Models\User;

/**
 * Who may do what with apparatus inventory corrections. Deliberately not model-policy
 * abilities (AdminCapabilityGate maps those to admin.* permissions and would hide a
 * member's own report), mirroring TakeHomeInspectionAccess.
 */
final class InventoryCorrectionAccess
{
    /** @var list<string> roles that may change the inventory of more than one apparatus at once */
    public const PROPAGATION_ROLES = ['super_admin', 'admin', 'logistics_admin'];

    /** Decide a correction for the reporting apparatus: the daily inspection reviewer rule. */
    public static function canReview(?User $user): bool
    {
        return $user instanceof User && InspectionReviewers::canReview($user);
    }

    /**
     * Apply a decision to every compatible apparatus or every apparatus carrying the item.
     * Stricter than review: a reviewer who holds only the delegated Fleet capability (for example a
     * Fleet Operations Manager) applies to one apparatus; fleet-wide inventory changes also need
     * a super administrator, administrator or Logistics administrator role.
     */
    public static function canPropagate(?User $user): bool
    {
        if (! self::canReview($user)) {
            return false;
        }
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasAnyRole(self::PROPAGATION_ROLES);
    }

    /**
     * A member who is part of the request: the one who reported it, or a member who reported the
     * same change later and was added to it (by account, or by the linked employee profile).
     */
    public static function owns(?User $user, ApparatusInventoryCorrection $correction): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        return self::reported($user, $correction) || self::participates($user, $correction);
    }

    /** The member who filed the request. */
    public static function reported(User $user, ApparatusInventoryCorrection $correction): bool
    {
        if ($correction->reporter_user_id !== null && (int) $correction->reporter_user_id === (int) $user->getKey()) {
            return true;
        }

        return $correction->reporter_employee_id !== null
            && $user->employee_profile_id !== null
            && (int) $correction->reporter_employee_id === (int) $user->employee_profile_id;
    }

    /** Listed as a participant; uses the loaded participants when present, so lists stay one query. */
    public static function participates(User $user, ApparatusInventoryCorrection $correction): bool
    {
        if ($correction->relationLoaded('participants')) {
            return $correction->participants->contains(static fn (ApparatusInventoryCorrectionParticipant $participant): bool => $participant->isUser($user));
        }

        return ApparatusInventoryCorrectionParticipant::query()
            ->where('apparatus_inventory_correction_id', $correction->getKey())
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->getKey());
                if ($user->employee_profile_id !== null) {
                    $query->orWhere('employee_id', $user->employee_profile_id);
                }
            })
            ->exists();
    }
}
