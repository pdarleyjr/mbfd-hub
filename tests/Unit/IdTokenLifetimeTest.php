<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Oidc\IdTokenResponse;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use OpenIDConnect\ClaimExtractor;
use OpenIDConnect\Interfaces\IdentityEntityInterface;
use OpenIDConnect\Interfaces\IdentityRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class IdTokenLifetimeTest extends TestCase
{
    public function test_builder_crossing_a_clock_second_keeps_exact_five_minute_lifetime(): void
    {
        $configuration = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText(str_repeat('t', 32)));
        $response = new IdTokenResponse($this->createMock(IdentityRepositoryInterface::class), new ClaimExtractor, $configuration, useMicroseconds: false, issuedByConfigured: 'https://mbfdhub.com');
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('cmd-client');
        $accessToken = $this->createMock(AccessTokenEntityInterface::class);
        $accessToken->method('getClient')->willReturn($client);
        $identity = $this->createMock(IdentityEntityInterface::class);
        $identity->expects(self::once())->method('getIdentifier')->willReturnCallback(function (): string {
            // The vendor has already captured iat before requesting the subject.
            // Cross a real clock second here, before our expiration override.
            usleep(1_100_000);

            return 'hub-user:42';
        });
        $builder = (new ReflectionMethod(IdTokenResponse::class, 'getBuilder'))->invoke($response, $accessToken, $identity);
        $claims = $builder->getToken($configuration->signer(), $configuration->signingKey())->claims();
        self::assertSame(300, $claims->get('exp')->getTimestamp() - $claims->get('iat')->getTimestamp());
        self::assertSame('hub-user:42', $claims->get('sub'));
        self::assertSame(['cmd-client'], $claims->get('aud'));
        self::assertSame('https://mbfdhub.com', $claims->get('iss'));
    }
}
