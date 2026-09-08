<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Oidc\AccessTokenRepository;
use App\Services\Oidc\AuthCodeRepository;
use App\Services\Oidc\IdentityRepository;
use App\Services\Oidc\IdTokenResponse;
use App\Services\Oidc\NoRefreshTokenRepository;
use App\Services\Oidc\OidcRequestContext;
use DateInterval;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\AuthorizationServer;
use Nyholm\Psr7\Response;
use OpenIDConnect\ClaimExtractor;
use OpenIDConnect\Claims\ClaimSet;
use OpenIDConnect\Grant\AuthCodeGrant;
use OpenIDConnect\Laravel\LaravelCurrentRequestService;

final class HubOidcServiceProvider extends \Laravel\Passport\PassportServiceProvider
{
    public function register(): void
    {
        \Laravel\Passport\Passport::ignoreRoutes();
        parent::register();
        $this->app->scoped(OidcRequestContext::class);
        $this->app->bind(\Laravel\Passport\Bridge\AccessTokenRepository::class, AccessTokenRepository::class);
    }

    public function boot(): void
    {
        parent::boot();
        \Laravel\Passport\Passport::tokensCan(['openid' => 'Sign in', 'profile' => 'Employee identity', 'email' => 'Email address']);
        $this->loadRoutesFrom(base_path('routes/oidc.php'));
    }

    protected function registerAuthorizationServer(): void
    {
        $this->app->singleton(AuthorizationServer::class, function (): AuthorizationServer {
            $key = $this->makeCryptKey('private');
            $encryptionKey = \Laravel\Passport\Passport::tokenEncryptionKey($this->app->make('encrypter'));
            $response = new IdTokenResponse(app(IdentityRepository::class),
                new ClaimExtractor(new ClaimSet('openid', ['employee_id', 'security_version', 'sid', 'application', 'nextcloud_uid'])),
                Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($key->getKeyContents()), InMemory::plainText($this->makeCryptKey('public')->getKeyContents())),
                ['kid' => hash('sha256', $this->makeCryptKey('public')->getKeyContents())], false,
                app(LaravelCurrentRequestService::class), $encryptionKey, (string) config('oidc.issuer'));
            $server = new AuthorizationServer(app(\Laravel\Passport\Bridge\ClientRepository::class), app(AccessTokenRepository::class),
                app(\Laravel\Passport\Bridge\ScopeRepository::class), $key, $encryptionKey, $response);
            $server->enableGrantType(new AuthCodeGrant(app(AuthCodeRepository::class), app(NoRefreshTokenRepository::class),
                new DateInterval('PT1M'), new Response, app(LaravelCurrentRequestService::class)), new DateInterval('PT8H'));

            return $server;
        });
    }
}
