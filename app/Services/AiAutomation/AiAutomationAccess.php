<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Models\User;

/**
 * Who may use the AI Response Automation screens (master switch, backlog, Knowledge Library, Playbooks).
 * Same shape as MailAccess: not a Gate ability, a current admin-panel entitlement is always required, and
 * super_admin or the admin.ai_automation.manage permission (direct or through a role) grants it. The
 * migration creates the permission and grants it to nobody.
 */
final class AiAutomationAccess
{
    public const MANAGE = 'admin.ai_automation.manage';

    public function canManage(?User $user): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed() || $user->must_change_password
            || ! $user->hasCurrentAdminPanelEntitlement()) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        // checkPermissionTo is false (not an exception) for a permission that does not exist yet.
        return $user->checkPermissionTo(self::MANAGE, 'web');
    }

    public static function allowsCurrentUser(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(self::class)->canManage($user);
    }
}
