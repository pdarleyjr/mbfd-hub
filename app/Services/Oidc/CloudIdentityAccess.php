<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcAccountLink;
use App\Models\User;

final class CloudIdentityAccess
{
    public function forUser(User $user): ?OidcAccountLink
    {
        $current = $user->fresh('employeeProfile');
        $employee = $current?->employeeProfile;
        if ($current === null || ! $current->isAuthenticationAllowed() || $current->must_change_password
            || $employee === null || $current->employee_id !== $employee->employee_id
            || (! $current->hasRole('super_admin') && ! $current->hasDirectWebPermission('app.cloud.access'))) {
            return null;
        }

        return OidcAccountLink::query()->where('application', 'cloud')->where('user_id', $current->id)
            ->where('employee_profile_id', $employee->id)->where('external_uid', '<>', '')->first();
    }
}
