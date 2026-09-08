<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Oidc\OidcIdentityPolicy;
use App\Services\Oidc\OidcRequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

final class OidcController
{
    public function discovery(): JsonResponse
    {
        $issuer = rtrim((string) config('oidc.issuer'), '/');

        return response()->json(['issuer' => $issuer, 'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token', 'userinfo_endpoint' => $issuer.'/oauth/userinfo', 'jwks_uri' => $issuer.'/oauth/jwks',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code'], 'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'], 'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'], 'code_challenge_methods_supported' => ['S256']]);
    }

    public function jwks(): JsonResponse
    {
        $key = str_replace('\\n', "\n", (string) config('passport.public_key'));
        if ($key === '') {
            $key = (string) file_get_contents(\Laravel\Passport\Passport::keyPath('oauth-public.key'));
        }
        config(['openid.token_headers.kid' => hash('sha256', $key)]);

        return app(\OpenIDConnect\Laravel\JwksController::class)();
    }

    public function authorize(Request $request, AuthorizationServer $server, OidcIdentityPolicy $policy): Response
    {
        return $this->respond(function () use ($request, $server, $policy) {
            $data = $request->query();
            // Do not silently satisfy authentication constraints this provider
            // cannot prove, or accept request objects it has not validated.
            foreach (['prompt', 'max_age', 'request', 'request_uri', 'id_token_hint'] as $unsupported) {
                if (array_key_exists($unsupported, $data)) {
                    throw OAuthServerException::invalidRequest($unsupported);
                }
            }
            if (isset($data['response_mode']) && $data['response_mode'] !== 'query') {
                throw OAuthServerException::invalidRequest('response_mode');
            }
            if (($data['response_type'] ?? null) !== 'code' || ($data['code_challenge_method'] ?? null) !== 'S256'
                || ! is_string($data['code_challenge'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $data['code_challenge']) !== 1
                || ! is_string($data['state'] ?? null) || $data['state'] === '' || strlen($data['state']) > 1024
                || ! is_string($data['nonce'] ?? null) || $data['nonce'] === '' || strlen($data['nonce']) > 255
                || ! is_string($data['scope'] ?? null) || ! in_array('openid', explode(' ', $data['scope']), true)
                || ! is_string($data['redirect_uri'] ?? null) || parse_url($data['redirect_uri'], PHP_URL_SCHEME) !== 'https') {
                throw OAuthServerException::invalidRequest('authorization');
            }
            $authorization = $server->validateAuthorizationRequest($this->psr($request));
            $user = $request->user('web');
            if (! $user instanceof User) {
                throw OAuthServerException::accessDenied();
            }
            $policy->user((string) $user->id, $authorization->getClient()->getIdentifier());
            // These are explicitly configured, confidential first-party clients.
            // Hub app grants are the authorization; no untrusted dynamic client.
            $authorization->setUser(new \Laravel\Passport\Bridge\User((string) $user->id));
            $authorization->setAuthorizationApproved(true);

            return $server->completeAuthorizationRequest($authorization, new PsrResponse);
        });
    }

    public function token(Request $request, AuthorizationServer $server): Response
    {
        return $this->respond(function () use ($request, $server) {
            app(OidcRequestContext::class)->session = null;

            return $server->respondToAccessTokenRequest($this->psr($request), new PsrResponse);
        });
    }

    public function userinfo(Request $request, ResourceServer $server, OidcIdentityPolicy $policy): Response
    {
        return $this->respond(function () use ($request, $server, $policy) {
            app(OidcRequestContext::class)->session = null;
            $validated = $server->validateAuthenticatedRequest($this->psr($request));
            $session = \App\Models\OidcSession::query()->where('access_token_id', $validated->getAttribute('oauth_access_token_id'))->first();
            if ($session === null) {
                throw OAuthServerException::accessDenied();
            }
            // Bound each authenticated server-side session independently. Many
            // employees legitimately share a confidential consumer's source IP.
            $rateKey = 'oidc-userinfo-session:'.$session->id;
            if (! \Illuminate\Support\Facades\RateLimiter::attempt($rateKey, 120, static fn (): bool => true, 60)) {
                return new PsrResponse(429, ['Content-Type' => 'application/json',
                    'Retry-After' => (string) \Illuminate\Support\Facades\RateLimiter::availableIn($rateKey)], '{"error":"rate_limit_exceeded"}');
            }

            $claims = $policy->claims($session);
            $scopes = $validated->getAttribute('oauth_scopes', []);
            if (! in_array('profile', $scopes, true)) {
                unset($claims['name']);
            }
            if (! in_array('email', $scopes, true)) {
                unset($claims['email'], $claims['email_verified']);
            }

            return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($claims, JSON_THROW_ON_ERROR));
        }, true);
    }

    private function psr(Request $request): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory;

        return (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);
    }

    private function respond(\Closure $operation, bool $resource = false): Response
    {
        try {
            $response = DB::transaction($operation);
        } catch (OAuthServerException $exception) {
            $response = $exception->generateHttpResponse(new PsrResponse);
            if ($resource) {
                $response = $response->withStatus(401);
            }
        }
        $result = (new HttpFoundationFactory)->createResponse($response);
        $result->headers->set('Cache-Control', 'no-store, private');

        return $result;
    }
}
