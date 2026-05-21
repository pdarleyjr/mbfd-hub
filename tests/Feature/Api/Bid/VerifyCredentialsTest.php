<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Bid;

use Tests\TestCase;

/**
 * Feature tests for the bid Worker credentials bridge.
 * Endpoint: POST /api/v2/verify-credentials
 *
 * These cover the middleware / validation paths (no DB writes). The
 * happy-path "valid creds → 200 with canonical identity" case is
 * verified live on staging after deploy — the test DB infra in this
 * project doesn't currently provision a transactional SQLite DB so
 * RefreshDatabase isn't usable here.
 */
class VerifyCredentialsTest extends TestCase
{
    private const SHARED_TOKEN = 'test-bid-reader-secret-do-not-use-in-prod';

    protected function setUp(): void
    {
        parent::setUp();
        // Stub the shared bearer token so the middleware doesn't fail-closed.
        config()->set('services.bid.reader_token', self::SHARED_TOKEN);
    }

    public function test_returns_503_when_bearer_token_is_unconfigured(): void
    {
        config()->set('services.bid.reader_token', null);

        $response = $this->postJson('/api/v2/verify-credentials', [
            'employee_id' => '14335',
            'password' => 'irrelevant',
        ]);

        $response->assertStatus(503);
        $response->assertJson(['error' => 'bridge_disabled']);
    }

    public function test_returns_401_without_authorization_header(): void
    {
        $response = $this->postJson('/api/v2/verify-credentials', [
            'employee_id' => '14335',
            'password' => 'irrelevant',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'missing_token']);
    }

    public function test_returns_401_with_wrong_bearer_token(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
            ->postJson('/api/v2/verify-credentials', [
                'employee_id' => '14335',
                'password' => 'irrelevant',
            ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'invalid_token']);
    }

    public function test_returns_401_with_empty_bearer_token(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '])
            ->postJson('/api/v2/verify-credentials', [
                'employee_id' => '14335',
                'password' => 'irrelevant',
            ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'invalid_token']);
    }

    public function test_returns_422_when_employee_id_is_missing(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::SHARED_TOKEN])
            ->postJson('/api/v2/verify-credentials', [
                'password' => 'foo',
            ]);

        $response->assertStatus(422);
    }

    public function test_returns_422_when_password_is_missing(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::SHARED_TOKEN])
            ->postJson('/api/v2/verify-credentials', [
                'employee_id' => '14335',
            ]);

        $response->assertStatus(422);
    }

    public function test_returns_422_when_employee_id_exceeds_max_length(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::SHARED_TOKEN])
            ->postJson('/api/v2/verify-credentials', [
                'employee_id' => str_repeat('x', 64),
                'password' => 'foo',
            ]);

        $response->assertStatus(422);
    }
}
