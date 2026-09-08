<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSession;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

final class AuthCodeRepository extends \Laravel\Passport\Bridge\AuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $policy = app(OidcIdentityPolicy::class);
        $clientId = $authCodeEntity->getClient()->getIdentifier();
        $user = $policy->user((string) $authCodeEntity->getUserIdentifier(), $clientId);
        parent::persistNewAuthCode($authCodeEntity);
        OidcSession::query()->create(['id' => bin2hex(random_bytes(32)), 'auth_code_id' => $authCodeEntity->getIdentifier(),
            'user_id' => $user->id, 'employee_profile_id' => $user->employee_profile_id, 'security_version' => $user->security_version,
            'client_id' => $clientId, 'application' => $policy->application($clientId),
            'external_uid' => $policy->application($clientId) === 'cloud' ? $policy->cloudLink($user)?->external_uid : null]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $session = OidcSession::query()->where('auth_code_id', $codeId)->first();
        if ($session === null) {
            return true;
        }
        try {
            app(OidcIdentityPolicy::class)->user((string) $session->user_id, $session->client_id, $session);
        } catch (OAuthServerException) {
            return true;
        }
        // Canonical User first, then session/code: the same lock order as revocation.
        $session = OidcSession::query()->whereKey($session->id)->lockForUpdate()->first();
        if ($session === null || $session->revoked_at !== null || $session->access_token_id !== null || parent::isAuthCodeRevoked($codeId)) {
            return true;
        }
        app(OidcRequestContext::class)->session = $session;

        return false;
    }
}
