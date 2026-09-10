<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Models\UserIdentityLink;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AuthentikOidcLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_code_pkce_login_maps_only_the_immutable_subject_and_issues_a_canonical_session(): void
    {
        Cache::clear();
        $employee = Employee::query()->create([
            'employee_id' => 'F00456',
            'name' => 'OIDC Pilot',
            'city_email' => 'oidc.pilot@miamibeachfl.gov',
            'roster_status' => 'active',
            'password' => 'test-only-password',
        ]);
        $user = User::factory()->create([
            'account_status' => 'active',
            'employee_profile_id' => $employee->getKey(),
            'employee_id' => $employee->employee_id,
        ]);
        $subject = 'bf1647a2-8bc0-4f42-b7c4-d10a7caee4a3';
        UserIdentityLink::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'authentik',
            'subject' => $subject,
            'provider_user_id' => '55',
            'status' => 'active',
        ]);
        $issuer = 'https://auth.mbfdhub.test/application/o/mbfd-hub/';
        $keys = $this->rsaKeys();
        $nonce = null;
        config([
            'identity.mode' => 'hybrid',
            'identity.canary_user_ids' => [],
            'identity.authentik.issuer' => $issuer,
            'identity.authentik.client_id' => 'mbfd-hub-test',
            'identity.authentik.client_secret' => 'test-client-secret',
            'identity.authentik.redirect_uri' => 'https://hub.mbfdhub.test/auth/identity/callback',
        ]);
        Http::fake([
            $issuer.'.well-known/openid-configuration' => Http::response([
                'issuer' => $issuer,
                'authorization_endpoint' => 'https://auth.mbfdhub.test/application/o/authorize/',
                'token_endpoint' => 'https://auth.mbfdhub.test/application/o/token/',
                'jwks_uri' => 'https://auth.mbfdhub.test/application/o/mbfd-hub/jwks/',
            ]),
            'https://auth.mbfdhub.test/application/o/token/' => function () use (&$nonce, $issuer, $subject, $keys) {
                return Http::response(['id_token' => JWT::encode([
                    'iss' => $issuer,
                    'aud' => 'mbfd-hub-test',
                    'sub' => $subject,
                    'nonce' => $nonce,
                    'iat' => now()->getTimestamp(),
                    'exp' => now()->addMinutes(5)->getTimestamp(),
                ], $keys['private'], 'RS256', 'test-key')]);
            },
            'https://auth.mbfdhub.test/application/o/mbfd-hub/jwks/' => Http::response([
                'keys' => [[
                    'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'test-key',
                    'n' => $keys['n'], 'e' => $keys['e'],
                ]],
            ]),
        ]);

        $start = $this->get('/auth/identity')->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertNotEmpty($query['code_challenge'] ?? null);
        $nonce = $query['nonce'] ?? null;

        $this->get('/auth/identity/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'single-use-code',
        ]))->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('authentication_sessions', [
            'user_id' => $user->getKey(),
            'security_version' => $user->security_version,
        ]);
    }

    public function test_matching_email_never_links_an_unknown_upstream_subject(): void
    {
        Cache::clear();
        $employee = Employee::query()->create([
            'employee_id' => 'F00789',
            'name' => 'Email Collision',
            'city_email' => 'collision@miamibeachfl.gov',
            'roster_status' => 'active',
            'password' => 'test-only-password',
        ]);
        $user = User::factory()->create([
            'name' => $employee->name,
            'email' => $employee->city_email,
            'account_status' => 'active',
            'employee_profile_id' => $employee->getKey(),
            'employee_id' => $employee->employee_id,
        ]);
        $issuer = 'https://auth.mbfdhub.test/application/o/mbfd-hub/';
        $keys = $this->rsaKeys();
        $nonce = null;
        config([
            'identity.mode' => 'hybrid',
            'identity.canary_user_ids' => [$user->getKey()],
            'identity.authentik.issuer' => $issuer,
            'identity.authentik.client_id' => 'mbfd-hub-test',
            'identity.authentik.client_secret' => 'test-client-secret',
            'identity.authentik.redirect_uri' => 'https://hub.mbfdhub.test/auth/identity/callback',
        ]);
        Http::fake([
            $issuer.'.well-known/openid-configuration' => Http::response([
                'issuer' => $issuer,
                'authorization_endpoint' => 'https://auth.mbfdhub.test/application/o/authorize/',
                'token_endpoint' => 'https://auth.mbfdhub.test/application/o/token/',
                'jwks_uri' => 'https://auth.mbfdhub.test/application/o/mbfd-hub/jwks/',
            ]),
            'https://auth.mbfdhub.test/application/o/token/' => function () use (&$nonce, $issuer, $keys) {
                return Http::response(['id_token' => JWT::encode([
                    'iss' => $issuer,
                    'aud' => 'mbfd-hub-test',
                    'sub' => 'aaaaaaaa-8bc0-4f42-b7c4-d10a7caee4a3',
                    'email' => 'collision@miamibeachfl.gov',
                    'nonce' => $nonce,
                    'iat' => now()->getTimestamp(),
                    'exp' => now()->addMinutes(5)->getTimestamp(),
                ], $keys['private'], 'RS256', 'test-key')]);
            },
            'https://auth.mbfdhub.test/application/o/mbfd-hub/jwks/' => Http::response([
                'keys' => [[
                    'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'test-key',
                    'n' => $keys['n'], 'e' => $keys['e'],
                ]],
            ]),
        ]);

        $start = $this->get('/auth/identity')->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $nonce = $query['nonce'] ?? null;
        $this->get('/auth/identity/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'single-use-code',
        ]))->assertRedirect('/login')->assertSessionHasErrors('employee_id');

        $this->assertGuest();
        $this->assertDatabaseCount('user_identity_links', 0);
    }

    /** @return array{private:string,n:string,e:string} */
    private function rsaKeys(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $localConfig = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (is_file($localConfig)) {
            $options['config'] = $localConfig;
        }
        $resource = openssl_pkey_new($options);
        self::assertNotFalse($resource);
        self::assertTrue(openssl_pkey_export($resource, $private, null, $options));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        return [
            'private' => $private,
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];
    }
}
