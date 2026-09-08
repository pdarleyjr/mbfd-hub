<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use DateTimeImmutable;
use Lcobucci\JWT\Builder;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

final class IdTokenResponse extends \OpenIDConnect\IdTokenResponse
{
    protected function getBuilder(AccessTokenEntityInterface $accessToken, IdentityEntityInterface $userEntity): Builder
    {
        return parent::getBuilder($accessToken, $userEntity)->expiresAt(new DateTimeImmutable('@'.(time() + 300)));
    }
}
