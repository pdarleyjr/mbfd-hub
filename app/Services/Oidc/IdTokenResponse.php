<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Builder;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

final class IdTokenResponse extends \OpenIDConnect\IdTokenResponse
{
    protected function getBuilder(AccessTokenEntityInterface $accessToken, IdentityEntityInterface $userEntity): Builder
    {
        $issuedAt = new DateTimeImmutable('@'.time());

        return parent::getBuilder($accessToken, $userEntity)
            ->issuedAt($issuedAt)
            ->expiresAt($issuedAt->add(new DateInterval('PT5M')));
    }
}
