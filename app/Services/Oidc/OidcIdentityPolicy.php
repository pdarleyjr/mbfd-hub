<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcAccountLink;
use App\Models\OidcSession;
use App\Models\User;
use Laravel\Passport\Client;
use League\OAuth2\Server\Exception\OAuthServerException;

final class OidcIdentityPolicy
{
    public function application(string $clientId): string
    {
        $matches = [];
        foreach (['cmd', 'cloud', 'meeting', 'prompt_studio'] as $application) {
            if ($clientId !== '' && config('oidc.clients.'.$application) === $clientId) {
                $matches[] = $application;
            }
        }
        if (count($matches) !== 1) {
            throw OAuthServerException::accessDenied();
        }

        return $matches[0];
    }

    public function user(string $userId, string $clientId, ?OidcSession $session = null): User
    {
        $application = $this->application($clientId);
        $client = Client::query()->find($clientId);
        $user = User::query()->lockForUpdate()->find($userId);
        $employee = $user?->employeeProfile;
        $linkedEmployee = $employee !== null && $user->employee_id === $employee->employee_id;
        // The Command Board admits exactly the 300 Command population. A Hub
        // administrator without a personnel record signs in as their canonical
        // Hub user; any account that claims a personnel link must still match it.
        $identityMatches = match ($application) {
            'cmd' => $linkedEmployee || ($employee === null && $user?->employee_profile_id === null),
            // Prompt Studio admits every active canonical Hub user. A linked user
            // must match their personnel record exactly; an unlinked user must not
            // claim a personnel number either (a half-linked identity is denied).
            'prompt_studio' => $linkedEmployee
                || ($user !== null && $employee === null && $user->employee_profile_id === null && blank($user->employee_id)),
            default => $linkedEmployee,
        };
        if ($client === null || $client->revoked || ! $client->confidential()
            || $user === null || ! $user->isAuthenticationAllowed() || $user->must_change_password
            || ! $identityMatches
            || ($application === 'cmd' && ! app(\App\Services\Security\Command300Access::class)->allows($user))
            // Prompt Studio, like the Command Board, has no per-app Hub permission:
            // sign-in is open to any active canonical account. What a signed-in
            // member can do inside Prompt Studio is decided by Prompt Studio.
            || (! in_array($application, ['cmd', 'prompt_studio'], true) && ! $user->hasRole('super_admin') && ! $user->hasDirectWebPermission('app.'.$application.'.access'))
            || ($session !== null && ($session->revoked_at !== null || $session->user_id !== $user->id
                || $session->employee_profile_id !== $employee?->id || $session->security_version !== (int) $user->security_version
                || $session->client_id !== $clientId || $session->application !== $application))) {
            throw OAuthServerException::accessDenied();
        }
        if ($application === 'cloud') {
            $link = $this->cloudLink($user);
            if ($link === null || ($session !== null && $session->external_uid !== $link->external_uid)) {
                throw OAuthServerException::accessDenied();
            }
        }

        return $user;
    }

    public function cloudLink(User $user): ?OidcAccountLink
    {
        return app(CloudIdentityAccess::class)->forUser($user);
    }

    /** @return array<string, mixed> */
    public function claims(OidcSession $session): array
    {
        $user = $this->user((string) $session->user_id, $session->client_id, $session);
        $claims = ['sub' => 'hub-user:'.$user->id,
            'name' => $user->display_name ?: $user->name, 'security_version' => (int) $user->security_version,
            'sid' => $session->id, 'application' => $session->application];
        // Never emit an empty personnel number; unlinked administrators omit it.
        if ($user->employeeProfile !== null && filled($user->employee_id)) {
            $claims['employee_id'] = (string) $user->employee_id;
        }
        if ($session->application === 'cmd') {
            $claims['command_access'] = '300';
        }
        $verification = app(\App\Services\Identity\CityEmailVerificationService::class);
        $email = $verification->connectedEmail($user);
        if ($email !== null) {
            $proof = $verification->status($user);
            $claims['email'] = $email;
            $claims['email_verified'] = ($proof?->verified_at !== null && $proof->email === $email)
                || ($user->bootstrap_onboarding_completed_at !== null && $user->email_verified_at !== null
                    && $verification->recoveryAddress($user) === $email);
        }
        if ($session->application === 'cloud') {
            $claims['nextcloud_uid'] = $this->cloudLink($user)?->external_uid;
        }
        if ($session->application === 'meeting') {
            $claims['role'] = app(\App\Services\Security\ApplicationRoleResolver::class)->forUser($user, 'meeting');
        }

        return $claims;
    }
}
