<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Identity\CanonicalLoginDestination;
use Tests\TestCase;

final class CanonicalLoginDestinationTest extends TestCase
{
    public function test_only_exact_known_same_origin_federation_routes_survive_and_queries_remain_opaque(): void
    {
        $destinations = app(CanonicalLoginDestination::class);
        foreach (['bid', 'media-control'] as $client) {
            $path = '/auth/'.$client.'/authorize?state=opaque%2Bstate%2Fvalue&redirect_uri=https%3A%2F%2Fcallback.mbfdhub.com%2Fa%3Fx%3D1%26y%3D2';
            self::assertSame($path, $destinations->federation($path));
            self::assertSame($path, $destinations->resolve(new User, url($path)));
        }

        foreach ([
            'https://evil.example/auth/bid/authorize', '//evil.example/auth/bid/authorize',
            '/%2f%2fevil.example/auth/bid/authorize', '/%255cevil.example/auth/bid/authorize',
            '/auth/bid/../authorize', '/auth/bid/authorize/extra', '/auth/cmd/authorize',
            '/auth/bid/authorize%0d%0aLocation:evil', '/auth/bid/authorize?state='.str_repeat('a', 8192),
        ] as $unsafe) {
            self::assertNull($destinations->federation($unsafe));
            self::assertSame('/', $destinations->resolve(new User, $unsafe));
        }
    }
}
