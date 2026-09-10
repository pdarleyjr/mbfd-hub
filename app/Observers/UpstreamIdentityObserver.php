<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Services\Identity\IdentitySynchronizationService;

final class UpstreamIdentityObserver
{
    public function saved(User $user): void
    {
        if (! $user->wasChanged([
            'account_status', 'security_version', 'employee_id', 'employee_profile_id',
            'name', 'email', 'display_name', 'rank', 'station', 'phone',
        ])) {
            return;
        }

        app(IdentitySynchronizationService::class)->request($user);
    }
}
