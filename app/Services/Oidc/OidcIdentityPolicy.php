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
        foreach (['cmd', 'cloud'] as $application) {
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
        if ($client === null || $client->revoked || ! $client->confidential()
            || $user === null || ! $user->isAuthenticationAllowed() || $user->must_change_password
            || $employee === null || $user->employee_id !== $employee->employee_id
            || (! $user->hasRole('super_admin') && ! $user->hasDirectWebPermission('app.'.$application.'.access'))
            || ($session !== null && ($session->revoked_at !== null || $session->user_id !== $user->id
                || $session->employee_profile_id !== $employee->id || $session->security_version !== (int) $user->security_version
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
        $claims = ['sub' => 'hub-user:'.$user->id, 'employee_id' => (string) $user->employee_id,
            'name' => $user->display_name ?: $user->name, 'security_version' => (int) $user->security_version,
            'sid' => $session->id, 'application' => $session->application];
        $verification = app(\App\Services\Identity\CityEmailVerificationService::class);
        $email = $verification->connectedEmail($user);
        if ($email !== null) {
            $proof = $verification->status($user);
            $claims['email'] = $email;
            $claims['email_verified'] = $proof?->verified_at !== null && $proof->email === $email;
        }
        if ($session->application === 'cloud') {
            $claims['nextcloud_uid'] = $this->cloudLink($user)?->external_uid;
        }

        return $claims;
    }
}
