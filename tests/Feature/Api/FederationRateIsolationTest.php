<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Exercise the deployed routes' real middleware stacks with inert controllers.
 * This measures admission isolation, not database throughput or live capacity.
 */
final class FederationRateIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.bid.federation_token' => 'local-bid-machine-fixture',
            'services.media_control.authorization.service_token' => 'local-media-machine-fixture',
        ]);
        Cache::flush();
        $this->travelTo(now()->startOfMinute());

        foreach (['bid.auth.exchange', 'bid.auth.revalidate', 'media-control.auth.exchange', 'media-control.auth.revalidate', 'media-control.auth.cloud-access'] as $operation) {
            $source = Route::getRoutes()->getByName('api.v2.'.$operation);
            $this->assertNotNull($source);
            Route::post('/_local-rate-fixture/'.$operation, static fn () => response()->json(['admitted' => true]))
                ->middleware($source->gatherMiddleware());
        }
    }

    public function test_media_polling_does_not_exhaust_either_app_code_exchange(): void
    {
        for ($request = 0; $request < 30; $request++) {
            $this->withToken('local-media-machine-fixture')
                ->postJson('/_local-rate-fixture/media-control.auth.revalidate')->assertOk();
        }

        $this->withToken('local-media-machine-fixture')
            ->postJson('/_local-rate-fixture/media-control.auth.exchange')->assertOk();
        $this->withToken('local-bid-machine-fixture')
            ->postJson('/_local-rate-fixture/bid.auth.exchange')->assertOk();
    }

    public function test_bid_polling_does_not_exhaust_bid_or_media_code_exchange(): void
    {
        for ($request = 0; $request < 30; $request++) {
            $this->withToken('local-bid-machine-fixture')
                ->postJson('/_local-rate-fixture/bid.auth.revalidate')->assertOk();
        }

        $this->withToken('local-bid-machine-fixture')
            ->postJson('/_local-rate-fixture/bid.auth.exchange')->assertOk();
        $this->withToken('local-media-machine-fixture')
            ->postJson('/_local-rate-fixture/media-control.auth.exchange')->assertOk();
    }

    public function test_existing_bid_identity_ceiling_is_preserved_pending_capacity_proof(): void
    {
        // The proposed two-second socket cache needs thirty checks/minute/socket.
        // Prefix isolation does NOT increase this limit or establish capacity.
        for ($request = 0; $request < 30; $request++) {
            $this->withToken('local-bid-machine-fixture')
                ->postJson('/_local-rate-fixture/bid.auth.revalidate')->assertOk();
        }
        $this->withToken('local-bid-machine-fixture')
            ->postJson('/_local-rate-fixture/bid.auth.revalidate')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_invalid_client_cannot_reach_the_identity_controller(): void
    {
        $this->withToken('wrong-local-fixture')
            ->postJson('/_local-rate-fixture/bid.auth.revalidate')
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_service_credential');
    }

    public function test_exchange_retains_a_thirty_attempt_ceiling(): void
    {
        for ($request = 0; $request < 30; $request++) {
            $this->withToken('local-bid-machine-fixture')
                ->postJson('/_local-rate-fixture/bid.auth.exchange')->assertOk();
        }
        $this->withToken('local-bid-machine-fixture')
            ->postJson('/_local-rate-fixture/bid.auth.exchange')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_media_retains_one_six_thousand_authenticated_client_budget(): void
    {
        for ($attempt = 0; $attempt < 5999; $attempt++) {
            RateLimiter::hit('federation-identity-client:media-control', 60);
        }
        $this->withToken('local-media-machine-fixture')
            ->postJson('/_local-rate-fixture/media-control.auth.revalidate')->assertOk();
        $this->withToken('local-media-machine-fixture')
            ->postJson('/_local-rate-fixture/media-control.auth.cloud-access')
            ->assertStatus(429)->assertJsonPath('error', 'rate_limit_exceeded')->assertHeader('Retry-After');
        $this->withToken('local-media-machine-fixture')
            ->postJson('/_local-rate-fixture/media-control.auth.exchange')->assertOk();
    }

    public function test_invalid_media_token_cannot_consume_authenticated_client_budget(): void
    {
        $this->withToken('wrong-local-fixture')
            ->postJson('/_local-rate-fixture/media-control.auth.revalidate')->assertUnauthorized();
        $this->assertSame(0, RateLimiter::attempts('federation-identity-client:media-control'));
    }
}
