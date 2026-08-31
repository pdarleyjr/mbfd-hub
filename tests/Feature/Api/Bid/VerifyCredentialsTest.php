<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Bid;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
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
    use RefreshDatabase;

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

    public function test_returns_admin_role_for_a_portal_employee_linked_to_a_current_bid_administrator(): void
    {
        $employee = Employee::create([
            'employee_id' => '14335',
            'name' => 'Portal Administrator',
            'rank' => 'Chief',
            'password' => Hash::make('portal-password'),
        ]);
        $user = User::factory()->create(['employee_id' => $employee->employee_id]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::SHARED_TOKEN])
            ->postJson('/api/v2/verify-credentials', [
                'employee_id' => $employee->employee_id,
                'password' => 'portal-password',
            ]);

        $response->assertOk();
        $response->assertJsonPath('role', 'admin');
    }

    public function test_returns_member_role_for_a_portal_employee_without_current_bid_administration_entitlement(): void
    {
        $employee = Employee::create([
            'employee_id' => '14336',
            'name' => 'Portal Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('portal-password'),
        ]);
        $user = User::factory()->create(['employee_id' => $employee->employee_id]);
        $user->assignRole(Role::findOrCreate('training_admin', 'web'));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::SHARED_TOKEN])
            ->postJson('/api/v2/verify-credentials', [
                'employee_id' => $employee->employee_id,
                'password' => 'portal-password',
            ]);

        $response->assertOk();
        $response->assertJsonPath('role', 'member');
    }
}
