<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use League\OAuth2\Server\Exception\OAuthServerException;
use OpenIDConnect\Interfaces\IdentityEntityInterface;
use OpenIDConnect\Interfaces\IdentityRepositoryInterface;

final class IdentityRepository implements IdentityRepositoryInterface
{
    public function getByIdentifier(string $identifier): IdentityEntityInterface
    {
        $session = app(OidcRequestContext::class)->session;
        if ($session === null || (string) $session->user_id !== $identifier) {
            throw OAuthServerException::accessDenied();
        }
        $identity = new IdentityEntity;
        $identity->setIdentifier('hub-user:'.$identifier);
        $claims = app(OidcIdentityPolicy::class)->claims($session);
        unset($claims['sub']);
        $identity->setClaims($claims);

        return $identity;
    }
}
