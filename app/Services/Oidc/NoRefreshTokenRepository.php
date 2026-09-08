<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

final class NoRefreshTokenRepository extends \Laravel\Passport\Bridge\RefreshTokenRepository
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return null;
    }
}
