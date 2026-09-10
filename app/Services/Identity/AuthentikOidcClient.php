<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class AuthentikOidcClient
{
    private const SESSION_KEY = 'auth.authentik_oidc_attempt';

    public function begin(Request $request, ?string $loginAttempt = null): string
    {
        $metadata = $this->metadata();
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'issued_at' => now()->getTimestamp(),
            'login_attempt' => $loginAttempt,
        ]);

        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{claims: array<string, mixed>, login_attempt: ?string, end_session_endpoint: ?string} */
    public function complete(Request $request): array
    {
        $attempt = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_array($attempt)
            || ! is_string($state)
            || ! is_string($code)
            || $code === ''
            || ! hash_equals((string) ($attempt['state'] ?? ''), $state)
            || now()->getTimestamp() - (int) ($attempt['issued_at'] ?? 0) > 600) {
            throw new RuntimeException('The identity response did not match a current login attempt.');
        }

        $metadata = $this->metadata();
        $token = $this->http()->asForm()->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post($metadata['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId(),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
                'code_verifier' => (string) $attempt['verifier'],
            ])->throw()->json();
        $idToken = $token['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            throw new RuntimeException('The identity provider did not return an ID token.');
        }

        $header = $this->jwtPart($idToken, 0);
        if (($header['alg'] ?? null) !== config('identity.authentik.allowed_algorithm')
            || ! is_string($header['kid'] ?? null)) {
            throw new RuntimeException('The identity provider used an unsupported signing key.');
        }
        $jwks = $this->http()->get($metadata['jwks_uri'])->throw()->json();
        if (! is_array($jwks)) {
            throw new RuntimeException('The identity provider returned an invalid signing-key set.');
        }
        $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));
        $this->validateClaims($claims, (string) $attempt['nonce']);

        $loginAttempt = $attempt['login_attempt'] ?? null;

        return [
            'claims' => $claims,
            'login_attempt' => is_string($loginAttempt) ? $loginAttempt : null,
            'end_session_endpoint' => is_string($metadata['end_session_endpoint'] ?? null)
                && $this->trustedUrl($metadata['end_session_endpoint'])
                    ? $metadata['end_session_endpoint'] : null,
        ];
    }

    public function endSessionUrl(string $endpoint): ?string
    {
        $redirect = (string) config('identity.authentik.post_logout_redirect_uri');
        if (! $this->trustedUrl($endpoint)
            || filter_var($redirect, FILTER_VALIDATE_URL) === false
            || parse_url($redirect, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return $endpoint.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'post_logout_redirect_uri' => $redirect,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,jwks_uri:string,end_session_endpoint?:string} */
    public function metadata(): array
    {
        $issuer = $this->issuer();

        return Cache::remember('identity.authentik.discovery.'.hash('sha256', $issuer), 300, function () use ($issuer): array {
            $metadata = $this->http()->get($issuer.'.well-known/openid-configuration')->throw()->json();
            if (! is_array($metadata) || ($metadata['issuer'] ?? null) !== $issuer) {
                throw new RuntimeException('The identity provider discovery issuer did not match configuration.');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                if (! is_string($metadata[$key] ?? null) || ! $this->trustedUrl($metadata[$key])) {
                    throw new RuntimeException('The identity provider discovery document contained an untrusted endpoint.');
                }
            }

            return $metadata;
        });
    }

    private function validateClaims(array $claims, string $nonce): void
    {
        $now = now()->getTimestamp();
        $skew = max(0, (int) config('identity.authentik.clock_skew_seconds'));
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (($claims['iss'] ?? null) !== $this->issuer()
            || ! in_array($this->clientId(), $audiences, true)
            || ! is_string($claims['sub'] ?? null)
            || $claims['sub'] === ''
            || ! is_string($claims['nonce'] ?? null)
            || ! hash_equals($nonce, $claims['nonce'])
            || (int) ($claims['exp'] ?? 0) < $now - $skew
            || (int) ($claims['iat'] ?? PHP_INT_MAX) > $now + $skew) {
            throw new RuntimeException('The identity provider returned invalid token claims.');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId()) {
            throw new RuntimeException('The identity provider returned an ambiguous token audience.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('identity.authentik.connect_timeout_seconds'))
            ->timeout((int) config('identity.authentik.timeout_seconds'));
    }

    private function issuer(): string
    {
        $issuer = (string) config('identity.authentik.issuer');
        if (! $this->trustedUrl($issuer)) {
            throw new RuntimeException('The identity provider issuer is not securely configured.');
        }

        return $issuer;
    }

    private function trustedUrl(string $url): bool
    {
        $issuerHost = parse_url((string) config('identity.authentik.issuer'), PHP_URL_HOST);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($issuerHost)
            && $issuerHost !== ''
            && hash_equals($issuerHost, (string) parse_url($url, PHP_URL_HOST));
    }

    private function clientId(): string
    {
        $value = (string) config('identity.authentik.client_id');
        if ($value === '') {
            throw new RuntimeException('The identity provider client ID is not configured.');
        }

        return $value;
    }

    private function clientSecret(): string
    {
        $value = (string) config('identity.authentik.client_secret');
        if ($value === '') {
            throw new RuntimeException('The identity provider client secret is not configured.');
        }

        return $value;
    }

    private function redirectUri(): string
    {
        $configured = (string) config('identity.authentik.redirect_uri');

        return $configured !== '' ? $configured : route('identity.callback');
    }

    /** @return array<string, mixed> */
    private function jwtPart(string $jwt, int $index): array
    {
        $parts = explode('.', $jwt);
        $encoded = $parts[$index] ?? '';
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $value = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($value)) {
            throw new RuntimeException('The identity provider returned an invalid token envelope.');
        }

        return $value;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
