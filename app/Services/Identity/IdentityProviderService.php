<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Contracts\Identity\IdentityProvider;
use App\Models\User;
use App\Models\UserIdentityLink;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use RuntimeException;

final class IdentityProviderService
{
    public function __construct(
        private readonly IdentityProvider $provider,
        private readonly CityEmailVerificationService $recoveryEmails,
        private readonly IdentitySynchronizationService $synchronizations,
    ) {}

    public function provision(User $user): UserIdentityLink
    {
        $current = $user->fresh('employeeProfile');
        if (! $current instanceof User || ! $current->isUpstreamIdentityEnabled()) {
            throw new RuntimeException('Only a current eligible MBFD identity can be provisioned.');
        }
        if (! $this->enabledFor($current)) {
            throw new RuntimeException('This account is not in the upstream identity rollout.');
        }
        $existing = $current->identityLinks()->where('provider', 'authentik')->first();
        if ($existing instanceof UserIdentityLink) {
            return $existing;
        }
        $recoveryEmail = $this->recoveryEmails->connectedEmail($current);
        if ($recoveryEmail === null) {
            throw new RuntimeException('An authoritative recovery address is required before activation.');
        }

        $provisioned = $this->provider->provision($current, $recoveryEmail);
        $link = DB::transaction(function () use ($current, $provisioned): UserIdentityLink {
            $locked = User::query()->lockForUpdate()->findOrFail($current->getKey());
            if (! $locked->isUpstreamIdentityEnabled()) {
                throw new RuntimeException('The local identity changed during upstream provisioning.');
            }

            return UserIdentityLink::query()->create([
                'user_id' => $locked->getKey(),
                'provider' => 'authentik',
                'subject' => $provisioned->subject,
                'provider_user_id' => $provisioned->providerUserId,
                'status' => 'active',
                'last_synced_at' => now(),
                'last_verified_at' => now(),
            ]);
        });

        $this->synchronizations->request($current);
        $broker = Password::broker();
        if (! $broker instanceof PasswordBroker) {
            throw new RuntimeException('The configured password broker cannot revoke legacy reset tokens.');
        }
        $broker->deleteToken($current);

        return $link;
    }

    public function recoveryLink(User $user): string
    {
        $current = $user->fresh();
        if (! $current instanceof User || ! $current->isUpstreamIdentityEnabled()) {
            throw new RuntimeException('Only a current eligible MBFD identity can recover an account.');
        }
        $link = $current->identityLinks()->where('provider', 'authentik')->first();
        if (! $link instanceof UserIdentityLink) {
            $link = $this->provision($current);
        }

        return $this->provider->recoveryLink($current, $link);
    }

    public function importCurrentPasswordHash(User $user): void
    {
        $current = $user->fresh();
        $link = $current?->identityLinks()->where('provider', 'authentik')->first();
        if (! $current instanceof User || ! $link instanceof UserIdentityLink) {
            throw new RuntimeException('A current upstream identity link is required for password migration.');
        }
        $this->provider->importPasswordHash($current, $link, (string) $current->getRawOriginal('password'));
    }

    public function requestSessionRevocation(User $user): void
    {
        $this->synchronizations->request($user, revokeSessions: true);
    }

    public function requestMfaReset(User $user): void
    {
        $this->synchronizations->request($user, revokeSessions: true, resetMfa: true);
    }

    public function requestSynchronization(User $user): void
    {
        $this->synchronizations->request($user);
    }

    /** @return array{totp:string,passkey:string} */
    public function enrollmentUrls(User $user): array
    {
        if (! $this->enabledFor($user)
            || ! $user->identityLinks()->where('provider', 'authentik')->exists()) {
            throw new RuntimeException('An active MBFD Identity link is required for authenticator enrollment.');
        }
        $issuer = (string) config('identity.authentik.issuer');
        $origin = parse_url($issuer, PHP_URL_SCHEME).'://'.parse_url($issuer, PHP_URL_HOST);
        if (! str_starts_with($origin, 'https://')) {
            throw new RuntimeException('The identity provider enrollment origin is not securely configured.');
        }

        return [
            'totp' => $origin.'/if/flow/default-authenticator-totp-setup/',
            'passkey' => $origin.'/if/flow/default-authenticator-webauthn-setup/',
        ];
    }

    public function enabledFor(User $user): bool
    {
        return match ((string) config('identity.mode')) {
            'authentik' => true,
            'hybrid' => $user->identityLinks()->where('provider', 'authentik')->exists()
                || in_array((int) $user->getKey(), config('identity.canary_user_ids', []), true),
            default => false,
        };
    }
}
