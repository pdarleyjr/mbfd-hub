<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSession;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

final class AccessTokenRepository extends \Laravel\Passport\Bridge\AccessTokenRepository
{
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $session = app(OidcRequestContext::class)->session;
        if ($session === null || $session->access_token_id !== null) {
            throw OAuthServerException::accessDenied();
        }
        app(OidcIdentityPolicy::class)->user((string) $accessTokenEntity->getUserIdentifier(), $accessTokenEntity->getClient()->getIdentifier(), $session);
        parent::persistNewAccessToken($accessTokenEntity);
        $session->forceFill(['access_token_id' => $accessTokenEntity->getIdentifier()])->save();
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $session = OidcSession::query()->where('access_token_id', $tokenId)->first();
        if ($session === null || parent::isAccessTokenRevoked($tokenId)) {
            return true;
        }
        try {
            app(OidcIdentityPolicy::class)->user((string) $session->user_id, $session->client_id, $session);
        } catch (OAuthServerException) {
            return true;
        }

        return false;
    }
}
