<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Employee;
use App\Models\User;

/**
 * The single authorization decision for Employee <-> Logistics messaging
 * (docs/employee-logistics-messaging.md section 3). Pages, widgets, navigation, badges and the
 * messaging service all ask this class, and the service asks again on every read and write.
 *
 * Member side: an active, current account (no pending password change) with a current linked
 * personnel profile — the same entitlement as the Employee Portal. A member only ever reaches
 * their own conversations; that scoping lives in the service queries.
 *
 * Logistics side (a shared team inbox): the account must hold the Admin Panel entitlement and be
 * a super administrator, hold the logistics_admin role, or hold the direct capability
 * admin.employee_messages.view (read) / admin.employee_messages.reply (read and reply). Merely
 * reaching /admin or the Communications group is never enough.
 *
 * Deliberately not exposed as Gate abilities: the global Gate::before answers true for every
 * super_admin, which would skip the account checks here (disabled account, pending password
 * change, missing personnel profile). Callers ask this class directly.
 */
final class EmployeeLogisticsMessagingAccess
{
    public const PERMISSION_VIEW = 'admin.employee_messages.view';

    public const PERMISSION_REPLY = 'admin.employee_messages.reply';

    public const LOGISTICS_ROLE = 'logistics_admin';

    /** @var \WeakMap<User, array{view: bool, reply: bool}>|null */
    private ?\WeakMap $decisions = null;

    /** The member's current personnel profile, or null when they may not use the member inbox. */
    public function memberEmployee(?User $user): ?Employee
    {
        if (! $user instanceof User || ! $this->usable($user) || $user->employee_profile_id === null) {
            return null;
        }
        $employee = $user->relationLoaded('employeeProfile') ? $user->employeeProfile : $user->employeeProfile()->first();

        return $employee instanceof Employee
            && (string) $employee->employee_id === (string) $user->employee_id
            && $employee->roster_status !== 'departed' ? $employee : null;
    }

    public function canUseMemberInbox(?User $user): bool
    {
        return $this->memberEmployee($user) !== null;
    }

    public function canViewLogisticsInbox(?User $user): bool
    {
        return $this->logisticsDecision($user)['view'];
    }

    public function canReplyAsLogistics(?User $user): bool
    {
        return $this->logisticsDecision($user)['reply'];
    }

    /**
     * Navigation, the badge and the page all ask on every admin request, so the answer is kept for
     * the lifetime of this User instance (one request; a fresh() model is a fresh decision).
     *
     * @return array{view: bool, reply: bool}
     */
    private function logisticsDecision(?User $user): array
    {
        if (! $user instanceof User) {
            return ['view' => false, 'reply' => false];
        }
        $this->decisions ??= new \WeakMap;

        return $this->decisions[$user] ??= $this->decideLogistics($user);
    }

    /** @return array{view: bool, reply: bool} */
    private function decideLogistics(User $user): array
    {
        if (! $this->adminUsable($user)) {
            return ['view' => false, 'reply' => false];
        }
        if ($user->hasRole('super_admin') || $user->hasRole(self::LOGISTICS_ROLE)) {
            return ['view' => true, 'reply' => true];
        }
        $direct = $user->permissions()->where('guard_name', 'web')
            ->whereIn('name', [self::PERMISSION_VIEW, self::PERMISSION_REPLY])->pluck('name')->all();
        $reply = in_array(self::PERMISSION_REPLY, $direct, true);

        return ['view' => $reply || in_array(self::PERMISSION_VIEW, $direct, true), 'reply' => $reply];
    }

    private function adminUsable(User $user): bool
    {
        return $this->usable($user) && $user->hasCurrentAdminPanelEntitlement();
    }

    private function usable(User $user): bool
    {
        return $user->isAuthenticationAllowed() && ! $user->must_change_password;
    }
}
