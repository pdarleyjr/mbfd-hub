<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSession;

final class OidcRequestContext
{
    public ?OidcSession $session = null;
}
