<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Contracts\Identity\IdentityProvider;
use App\Data\Identity\ProvisionedIdentity;
use App\Models\User;
use App\Models\UserIdentityLink;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AuthentikIdentityProvider implements IdentityProvider
{
    public function provision(User $user, ?string $recoveryEmail): ProvisionedIdentity
    {
        $employeeId = $this->employeeId($user);
        $response = $this->client()->post('/core/users/', [
            'username' => $employeeId,
            'name' => (string) $user->name,
            'is_active' => $user->isUpstreamIdentityEnabled(),
            'email' => $recoveryEmail ?? '',
            'attributes' => [
                'mbfd_employee_id' => $employeeId,
                'mbfd_employee_profile_id' => $user->employee_profile_id,
            ],
            'path' => 'users/mbfd',
            'type' => 'internal',
        ])->throw()->json();

        $providerUserId = $response['pk'] ?? null;
        $subject = $response['uuid'] ?? null;
        if ((! is_int($providerUserId) && ! ctype_digit((string) $providerUserId))
            || ! is_string($subject)
            || preg_match('/^[0-9a-f-]{36}$/iD', $subject) !== 1) {
            throw new RuntimeException('The identity provider returned an invalid user identifier.');
        }

        return new ProvisionedIdentity($subject, (string) $providerUserId);
    }

    public function synchronize(User $user, UserIdentityLink $link): void
    {
        $this->assertLink($user, $link);
        $email = app(CityEmailVerificationService::class)->connectedEmail($user);
        $this->client()->patch('/core/users/'.$link->provider_user_id.'/', [
            'username' => $this->employeeId($user),
            'name' => (string) $user->name,
            'is_active' => $user->isUpstreamIdentityEnabled(),
            'email' => $email ?? '',
            'attributes' => [
                'mbfd_employee_id' => $this->employeeId($user),
                'mbfd_employee_profile_id' => $user->employee_profile_id,
            ],
            'path' => 'users/mbfd',
            'type' => 'internal',
        ])->throw();
    }

    public function recoveryLink(User $user, UserIdentityLink $link): string
    {
        $this->assertLink($user, $link);
        $response = $this->client()->post('/core/users/'.$link->provider_user_id.'/recovery/', [
            'token_duration' => 'seconds='.max(60, (int) config('identity.authentik.recovery_link_seconds')),
        ])->throw()->json();
        $recoveryLink = $response['link'] ?? null;
        if (! is_string($recoveryLink) || ! $this->trustedProviderUrl($recoveryLink)) {
            throw new RuntimeException('The identity provider returned an invalid recovery URL.');
        }

        return $recoveryLink;
    }

    public function revokeSessions(User $user, UserIdentityLink $link): void
    {
        $this->assertLink($user, $link);
        foreach ($this->sessions($link) as $session) {
            $sessionId = $session['uuid'] ?? null;
            if (! is_string($sessionId) || preg_match('/^[0-9a-f-]{36}$/iD', $sessionId) !== 1) {
                throw new RuntimeException('The identity provider returned an invalid session identifier.');
            }
            $this->client()->delete('/core/authenticated_sessions/'.$sessionId.'/')->throw();
        }
    }

    public function securityState(User $user, UserIdentityLink $link): array
    {
        $this->assertLink($user, $link);
        $devices = $this->devices($link);
        $confirmed = array_values(array_filter($devices, static fn (array $device): bool => ($device['confirmed'] ?? false) === true));
        $passkeys = $this->deviceCount($confirmed, 'webauthn');
        $totp = $this->deviceCount($confirmed, 'totp');
        $static = $this->deviceCount($confirmed, 'static');

        return [
            'mfa_enrolled' => $passkeys + $totp + $static > 0,
            'passkey_count' => $passkeys,
            'totp_count' => $totp,
            'recovery_code_count' => $static,
            'active_session_count' => count($this->sessions($link)),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function resetMfa(User $user, UserIdentityLink $link): void
    {
        $this->assertLink($user, $link);
        foreach ($this->devices($link) as $device) {
            $model = strtolower((string) ($device['meta_model_name'] ?? ''));
            $type = match (true) {
                str_contains($model, 'webauthn') => 'webauthn',
                str_contains($model, 'totp') => 'totp',
                str_contains($model, 'static') => 'static',
                default => null,
            };
            $id = $device['pk'] ?? null;
            if ($type === null || (! is_int($id) && ! is_string($id)) || (string) $id === '') {
                throw new RuntimeException('The identity provider returned an unsupported authenticator device.');
            }
            $this->client()->delete('/authenticators/admin/'.$type.'/'.rawurlencode((string) $id).'/')->throw();
        }
    }

    public function importPasswordHash(User $user, UserIdentityLink $link, string $passwordHash): void
    {
        $this->assertLink($user, $link);
        if (! (bool) config('identity.authentik.import_password_hash')) {
            throw new RuntimeException('Identity-provider password-hash import is not enabled.');
        }
        $this->client()->post('/core/users/'.$link->provider_user_id.'/set_password_hash/', [
            'password' => $passwordHash,
        ])->throw();
    }

    private function client(): PendingRequest
    {
        $baseUrl = (string) config('identity.authentik.api_url');
        $token = (string) config('identity.authentik.api_token');
        if (! $this->trustedProviderUrl($baseUrl) || $token === '') {
            throw new RuntimeException('The identity provider API is not securely configured.');
        }

        return Http::baseUrl($baseUrl.'/api/v3')
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout((int) config('identity.authentik.connect_timeout_seconds'))
            ->timeout((int) config('identity.authentik.timeout_seconds'));
    }

    private function employeeId(User $user): string
    {
        $employeeId = trim((string) $user->employee_id);
        if ($employeeId === '' || $user->employee_profile_id === null) {
            throw new RuntimeException('A canonical Employee ID is required for upstream identity operations.');
        }

        return $employeeId;
    }

    private function assertLink(User $user, UserIdentityLink $link): void
    {
        if ($link->provider !== 'authentik'
            || $link->user_id !== $user->getKey()
            || ! ctype_digit((string) $link->provider_user_id)) {
            throw new RuntimeException('The upstream identity link is invalid.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function sessions(UserIdentityLink $link): array
    {
        $response = $this->client()->get('/core/authenticated_sessions/', [
            'user' => $link->provider_user_id,
            'page_size' => 100,
        ])->throw()->json();
        $sessions = $response['results'] ?? null;
        if (! is_array($sessions)) {
            throw new RuntimeException('The identity provider returned an invalid session inventory.');
        }
        if (($response['pagination']['next'] ?? 0) !== 0) {
            throw new RuntimeException('The identity provider session inventory exceeded the bounded page.');
        }

        return array_values($sessions);
    }

    /** @return list<array<string, mixed>> */
    private function devices(UserIdentityLink $link): array
    {
        $devices = $this->client()->get('/authenticators/admin/all/', [
            'user' => $link->provider_user_id,
        ])->throw()->json();
        if (! is_array($devices) || ! array_is_list($devices)) {
            throw new RuntimeException('The identity provider returned an invalid authenticator inventory.');
        }

        return array_values(array_filter($devices, 'is_array'));
    }

    /** @param list<array<string, mixed>> $devices */
    private function deviceCount(array $devices, string $needle): int
    {
        return count(array_filter($devices, static fn (array $device): bool => str_contains(
            strtolower((string) ($device['meta_model_name'] ?? $device['type'] ?? '')),
            $needle,
        )));
    }

    private function trustedProviderUrl(string $url): bool
    {
        $issuer = (string) config('identity.authentik.issuer');
        $expectedHost = parse_url($issuer, PHP_URL_HOST);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($expectedHost)
            && $expectedHost !== ''
            && hash_equals($expectedHost, (string) parse_url($url, PHP_URL_HOST));
    }
}
