<?php

declare(strict_types=1);

namespace App\Contracts\Identity;

use App\Data\Identity\ProvisionedIdentity;
use App\Models\User;
use App\Models\UserIdentityLink;

interface IdentityProvider
{
    public function provision(User $user, ?string $recoveryEmail): ProvisionedIdentity;

    public function synchronize(User $user, UserIdentityLink $link): void;

    public function recoveryLink(User $user, UserIdentityLink $link): string;

    public function revokeSessions(User $user, UserIdentityLink $link): void;

    /** @return array{mfa_enrolled:bool,passkey_count:int,totp_count:int,recovery_code_count:int,active_session_count:int,checked_at:string} */
    public function securityState(User $user, UserIdentityLink $link): array;

    public function resetMfa(User $user, UserIdentityLink $link): void;

    public function importPasswordHash(User $user, UserIdentityLink $link, string $passwordHash): void;
}
