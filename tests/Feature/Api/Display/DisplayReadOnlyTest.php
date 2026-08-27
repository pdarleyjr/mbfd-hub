<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Display;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The /api/display/* surface must be strictly GET-only. The display.readonly
 * middleware rejects any mutating verb with a 405 before a controller runs.
 */
class DisplayReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $token = Str::random(48);
        config(['services.display_api.token' => $token]);
        $this->withHeader('X-Display-Token', $token);
    }

    public function test_get_snapshot_returns_200(): void
    {
        $this->getJson('/api/display/snapshot')->assertStatus(200);
    }

    public function test_post_snapshot_is_rejected_405(): void
    {
        $this->postJson('/api/display/snapshot')
            ->assertStatus(405)
            ->assertJson(['message' => 'Method Not Allowed. Display API is read-only.']);
    }

    public function test_put_snapshot_is_rejected_405(): void
    {
        $this->putJson('/api/display/snapshot')
            ->assertStatus(405)
            ->assertJson(['message' => 'Method Not Allowed. Display API is read-only.']);
    }

    public function test_patch_snapshot_is_rejected_405(): void
    {
        $this->patchJson('/api/display/snapshot')
            ->assertStatus(405)
            ->assertJson(['message' => 'Method Not Allowed. Display API is read-only.']);
    }

    public function test_delete_snapshot_is_rejected_405(): void
    {
        $this->deleteJson('/api/display/snapshot')
            ->assertStatus(405)
            ->assertJson(['message' => 'Method Not Allowed. Display API is read-only.']);
    }

    public function test_health_endpoint_is_get_only(): void
    {
        $this->getJson('/api/display/health')->assertStatus(200);
        $this->postJson('/api/display/health')->assertStatus(405);
    }
}
