<?php

declare(strict_types=1);

namespace App\Data\Identity;

final readonly class ProvisionedIdentity
{
    public function __construct(
        public string $subject,
        public string $providerUserId,
    ) {}
}
