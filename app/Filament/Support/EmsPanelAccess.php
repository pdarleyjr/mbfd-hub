<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use App\Services\Ems\EmsAccess;

/**
 * "May this person see the Admin -> EMS screens?" asked by the sidebar on every admin page.
 *
 * EmsAccess is the real decision. Its Captain 5 branch reads the duty roster, which costs a few
 * queries, and every admin page has a small query budget. A super administrator passes every EMS
 * check, so that case is answered here from the already loaded roles before EmsAccess is asked.
 * Everyone else goes through EmsAccess unchanged, and every action re-checks it on the server.
 */
final class EmsPanelAccess
{
    public static function canView(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if ($user->isAuthenticationAllowed() && ! $user->must_change_password && $user->hasRole('super_admin')) {
            return true;
        }

        return app(EmsAccess::class)->canView($user);
    }
}
