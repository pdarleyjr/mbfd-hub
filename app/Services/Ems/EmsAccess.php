<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequestEvent;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\Captain5AccessDecision;

/**
 * The one EMS authorization decision (docs/ems/ems-inventory-contract.md section 7).
 *
 *  - view      : Captain 5 view (Captain5Access) OR Admin -> EMS view/manage
 *  - act       : Captain 5 act  (Captain5Access) OR Admin -> EMS manage
 *  - configure : Captain 5 admin (captain5.admin) OR Admin -> EMS manage
 *
 * The Admin -> EMS grants only count together with the direct `admin.access` permission (the
 * same rule AdminCapabilityGate applies to every other admin.* capability). Super administrators
 * pass everything. Members who are not staff have no EMS staff access at all.
 */
final class EmsAccess
{
    public const VIEW = 'admin.ems.view';

    public const MANAGE = 'admin.ems.manage';

    public const ABILITIES = ['view', 'act', 'configure'];

    public function __construct(private readonly Captain5Access $captain5) {}

    public function allows(?User $user, string $ability): bool
    {
        return match ($ability) {
            'view' => $this->canView($user),
            'act' => $this->canAct($user),
            'configure' => $this->canConfigure($user),
            default => false,
        };
    }

    public function canView(?User $user): bool
    {
        return $this->usable($user)
            && ($this->viaCaptain5($user) || $this->hasAdminGrant($user, [self::VIEW, self::MANAGE]));
    }

    public function canAct(?User $user): bool
    {
        return $this->usable($user)
            && ($this->viaCaptain5($user) || $this->hasAdminGrant($user, [self::MANAGE]));
    }

    public function canConfigure(?User $user): bool
    {
        return $this->usable($user)
            && ($this->captain5->decide($user)->canConfigure || $this->hasAdminGrant($user, [self::MANAGE]));
    }

    /** Captain 5 when the person qualifies for the Captain 5 page, otherwise EMS Admin. */
    public function staffContext(User $user): string
    {
        return $this->viaCaptain5($user)
            ? InventoryRequestEvent::CONTEXT_CAPTAIN5
            : InventoryRequestEvent::CONTEXT_EMS_ADMIN;
    }

    /**
     * Whether Captain5Access admits this person for a reason that is about Captain 5 itself (officer
     * rank, a Captain 5 / EMS Captain seat, an explicit allow, a Fleet reviewer, super administrator).
     *
     * Captain5Access also admits every holder of `admin.access` as a plain "administrator". That
     * source is deliberately NOT enough here: otherwise `admin.ems.view` could never be read-only,
     * because every Admin-panel user would already pass captain5-act. Admin-panel users get EMS
     * access only from their explicit admin.ems.* grants (or captain5.admin, see hasAdminGrant()).
     */
    public function viaCaptain5(User $user): bool
    {
        $decision = $this->captain5->decide($user);

        return $decision->canViewData
            && array_diff($decision->sources, [Captain5AccessDecision::SOURCE_ADMINISTRATOR]) !== [];
    }

    /** @return array{view: bool, act: bool, configure: bool} */
    public function summary(?User $user): array
    {
        return [
            'view' => $this->canView($user),
            'act' => $this->canAct($user),
            'configure' => $this->canConfigure($user),
        ];
    }

    private function usable(?User $user): bool
    {
        return $user instanceof User && $user->isAuthenticationAllowed() && ! $user->must_change_password;
    }

    /** @param list<string> $permissions */
    private function hasAdminGrant(User $user, array $permissions): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        // A Captain 5 deny override also ends EMS access that came from an Admin grant.
        if ($this->captain5->decide($user)->denied) {
            return false;
        }
        if (! $user->hasDirectWebPermission('admin.access')) {
            return false;
        }
        // The Captain 5 administrator grant is an explicit EMS-management grant too.
        if (in_array(self::MANAGE, $permissions, true) && $user->hasDirectWebPermission(Captain5Access::PERMISSION_ADMIN)) {
            return true;
        }
        foreach ($permissions as $permission) {
            if ($user->hasDirectWebPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
