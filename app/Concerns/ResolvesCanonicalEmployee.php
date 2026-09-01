<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Employee;
use App\Services\Identity\AuthenticatedMemberContextResolver;

trait ResolvesCanonicalEmployee
{
    protected function authenticatedEmployee(): Employee
    {
        return app(AuthenticatedMemberContextResolver::class)
            ->resolve(request())
            ->actor()
            ->requireEmployee();
    }
}
