<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use OpenIDConnect\Claims\Traits\WithClaims;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

final class IdentityEntity implements IdentityEntityInterface
{
    use EntityTrait, WithClaims;
}
