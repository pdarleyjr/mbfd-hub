<?php

declare(strict_types=1);

namespace App\Services\Bid;

use App\Models\User;

/**
 * Resolves the Bid role exclusively from the current Hub Admin Panel
 * entitlement. This deliberately has no rank, title, or employee-ID fallback.
 */
class BidRoleResolver
{
    public function roleFor(User $user): string
    {
        return $user->hasCurrentAdminPanelEntitlement() ? 'admin' : 'member';
    }
}
