<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use LogicException;

final class CredentialInspector
{
    private string $fingerprintKey;

    public function __construct(?string $fingerprintKey = null)
    {
        $key = $fingerprintKey;
        if ($key === null && function_exists('config')) {
            $configured = config('app.key');
            $key = is_string($configured) ? $configured : null;
        }

        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? null : $decoded;
        }

        if ($key === null || $key === '') {
            throw new LogicException('A non-empty application key is required for identity preview fingerprints.');
        }

        $this->fingerprintKey = $key;
    }

    /** @return array{state: string, algorithm: string|null} */
    public function inspect(?string $hash): array
    {
        if ($hash === null || $hash === '') {
            return [
                'state' => 'HASH_MISSING',
                'algorithm' => null,
            ];
        }

        $passwordInfo = password_get_info($hash);
        $algorithm = match ($passwordInfo['algoName']) {
            'bcrypt' => 'BCRYPT',
            'argon2id' => 'ARGON2ID',
            'argon2i' => 'ARGON2I',
            default => 'UNSUPPORTED',
        };

        return [
            'state' => 'HASH_PRESENT',
            'algorithm' => $algorithm,
        ];
    }

    public function compare(?string $userHash, ?string $employeeHash): string
    {
        if ($userHash === null || $userHash === '') {
            return 'USER_HASH_MISSING';
        }

        if ($employeeHash === null || $employeeHash === '') {
            return 'EMPLOYEE_HASH_MISSING';
        }

        $user = $this->inspect($userHash);
        $employee = $this->inspect($employeeHash);

        if ($user['algorithm'] !== 'BCRYPT' || $employee['algorithm'] !== 'BCRYPT') {
            return 'ALGORITHM_INCOMPATIBLE';
        }

        return hash_equals($userHash, $employeeHash) ? 'SAME_HASH' : 'DIFFERENT_HASH';
    }

    public function fingerprintPayload(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->fingerprintKey);
    }
}
