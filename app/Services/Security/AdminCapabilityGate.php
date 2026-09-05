<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;

final class AdminCapabilityGate
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function decision(User $user, string $ability, array $arguments): ?bool
    {
        if (! $user->hasDirectWebPermission('admin.access')) {
            return null;
        }

        $subject = $arguments[0] ?? null;
        $modelClass = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : null);
        $capability = $modelClass === null
            ? null
            : config('admin-capabilities.models.'.$modelClass);

        if (! is_string($capability)) {
            return null;
        }

        $access = in_array($ability, ['viewAny', 'view'], true) ? 'view' : 'manage';

        return $user->hasDirectWebPermission($capability.'.'.$access);
    }
}
