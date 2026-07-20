<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Display;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the shared-secret guard on the read-only /api/display/* surface.
 *
 * When DISPLAY_API_TOKEN is unset the guard is a no-op (open). When set, callers
 * must present a matching X-Display-Token header; this protects the staff-only
 * personnel roster from being reachable directly on the Hub origin.
 */
final class DisplayTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_when_token_not_configured(): void
    {
        config(['services.display_api.token' => null]);

        $this->getJson('/api/display/snapshot')->assertStatus(200);
    }

    public function test_forbidden_without_token_when_configured(): void
    {
        config(['services.display_api.token' => 'super-secret-display-token']);

        $this->getJson('/api/display/snapshot')->assertStatus(403);
        $this->getJson('/api/display/stations/1/personnel')->assertStatus(403);
    }

    public function test_forbidden_with_wrong_token(): void
    {
        config(['services.display_api.token' => 'super-secret-display-token']);

        $this->getJson('/api/display/snapshot', ['X-Display-Token' => 'nope'])
            ->assertStatus(403);
    }

    public function test_allowed_with_correct_token(): void
    {
        config(['services.display_api.token' => 'super-secret-display-token']);

        $this->getJson('/api/display/snapshot', ['X-Display-Token' => 'super-secret-display-token'])
            ->assertStatus(200);
    }
}
