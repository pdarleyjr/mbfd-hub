<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

final readonly class TemporaryCredentialFingerprint
{
    private const DOMAIN = 'mbfd-hub:temporary-credential-fingerprint:v1';

    public function forPassword(string $password): string
    {
        $applicationKey = (string) config('app.key');
        if ($applicationKey === '') {
            throw new RuntimeException('The application key is required for temporary credential fingerprinting.');
        }

        $domainKey = hash_hmac('sha256', self::DOMAIN, $applicationKey, true);

        return hash_hmac('sha256', $password, $domainKey);
    }
}
