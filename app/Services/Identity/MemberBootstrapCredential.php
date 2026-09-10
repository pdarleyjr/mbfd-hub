<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Support\Facades\Hash;
use Throwable;

final class MemberBootstrapCredential
{
    public function available(): bool
    {
        if (! (bool) config('identity.member_bootstrap.enabled')) {
            return false;
        }

        $hash = config('identity.member_bootstrap.password_hash');

        return is_string($hash) && $hash !== '' && $this->supportedHash($hash);
    }

    public function matches(string $password): bool
    {
        $hash = config('identity.member_bootstrap.password_hash');
        if (! $this->available() || ! is_string($hash)) {
            return false;
        }

        try {
            return Hash::check($password, $hash);
        } catch (Throwable) {
            return false;
        }
    }

    private function supportedHash(string $hash): bool
    {
        $algorithm = password_get_info($hash)['algoName'] ?? 'unknown';

        return in_array($algorithm, ['bcrypt', 'argon2i', 'argon2id'], true);
    }
}
