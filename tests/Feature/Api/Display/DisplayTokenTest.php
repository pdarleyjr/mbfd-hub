<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Display;

use App\Http\Middleware\EnsureDisplayToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies the shared-secret guard on the read-only /api/display/* surface.
 *
 * The guard fails closed when DISPLAY_API_TOKEN is unset. When configured, callers
 * must present a matching X-Display-Token header; this protects the staff-only
 * personnel roster from being reachable directly on the Hub origin.
 */
final class DisplayTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_is_unavailable_when_token_is_not_configured(): void
    {
        config(['services.display_api.token' => null]);

        $this->getJson('/api/display/stations/1/personnel')->assertStatus(503);
    }

    public function test_forbidden_without_token_when_configured(): void
    {
        config(['services.display_api.token' => Str::random(48)]);

        $this->getJson('/api/display/snapshot')->assertStatus(403);
        $this->getJson('/api/display/stations/1/personnel')->assertStatus(403);
    }

    public function test_forbidden_with_wrong_token(): void
    {
        config(['services.display_api.token' => Str::random(48)]);

        $this->withHeader('X-Display-Token', Str::random(48))
            ->getJson('/api/display/snapshot')
            ->assertStatus(403);
    }

    public function test_allowed_with_correct_token(): void
    {
        $token = Str::random(48);
        config(['services.display_api.token' => $token]);

        $response = app(EnsureDisplayToken::class)->handle(
            Request::create('/api/display/snapshot', 'GET', server: ['HTTP_X_DISPLAY_TOKEN' => $token]),
            static fn (): Response => response()->noContent(),
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_options_passes_through_when_the_token_is_not_configured(): void
    {
        config(['services.display_api.token' => null]);

        $response = app(EnsureDisplayToken::class)->handle(
            Request::create('/api/display/snapshot', 'OPTIONS'),
            static fn (): Response => response()->noContent(),
        );

        $this->assertSame(204, $response->getStatusCode());
    }
}
