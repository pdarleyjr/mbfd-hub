<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;

final class ApplicationRoleResolver
{
    public function forUser(User $user, string $application): ?string
    {
        if (! $user->isAuthenticationAllowed() || $user->must_change_password) {
            return null;
        }
        $super = $user->hasRole('super_admin');
        if (! $super && ! $user->hasDirectWebPermission('app.'.$application.'.access')) {
            return null;
        }
        $administrator = $super || $user->hasDirectWebPermission('app.'.$application.'.admin');

        return match ($application) {
            'bid' => $administrator ? 'admin' : 'member',
            'media_control' => $administrator ? 'platform_admin' : 'user',
            default => null,
        };
    }
}
