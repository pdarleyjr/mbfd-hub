<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\User;

/**
 * Who may use the Email Center. Deliberately not a Gate ability: Gate::before would answer true for every
 * super administrator and skip the account checks here. A current admin-panel entitlement is always required,
 * so a stale grant on a disabled or password-reset account opens nothing. The mailbox is shared, so there is no
 * per-thread ACL; these three levels are the whole boundary, and the services and routes enforce them
 * server-side whatever the page shows.
 *
 *   canView    admin.email.view   OR legacy admin.communications.view
 *   canSend    canView AND (admin.email.send OR legacy admin.communications.send)
 *   canManage  canView AND admin.email.manage
 *
 * The legacy pair is a fallback, not a second system: it keeps everyone who could read or send mail before the
 * Email permissions existed working at deploy, when no grant can be made (the deploy gate refuses role and
 * permission changes). Nothing else about admin.communications.* changes. Permissions are honoured whether
 * granted directly or through a role, as they were for the pages Email Center replaces.
 */
final class MailAccess
{
    public const VIEW = 'admin.email.view';

    public const SEND = 'admin.email.send';

    public const MANAGE = 'admin.email.manage';

    public const LEGACY_VIEW = 'admin.communications.view';

    public const LEGACY_SEND = 'admin.communications.send';

    public function canView(?User $user): bool
    {
        return $this->entitled($user) && $this->holds($user, self::VIEW, self::LEGACY_VIEW);
    }

    public function canSend(?User $user): bool
    {
        return $this->canView($user) && $this->holds($user, self::SEND, self::LEGACY_SEND);
    }

    public function canManage(?User $user): bool
    {
        return $this->canView($user) && $this->holds($user, self::MANAGE);
    }

    private function entitled(?User $user): bool
    {
        return $user instanceof User && $user->isAuthenticationAllowed() && ! $user->must_change_password
            && $user->hasCurrentAdminPanelEntitlement();
    }

    private function holds(User $user, string ...$permissions): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        foreach ($permissions as $permission) {
            // checkPermissionTo is false (not an exception) for a permission that does not exist yet.
            if ($user->checkPermissionTo($permission, 'web')) {
                return true;
            }
        }

        return false;
    }
}
