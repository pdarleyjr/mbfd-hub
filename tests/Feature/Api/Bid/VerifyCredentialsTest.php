<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Bid;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Feature tests for the bid Worker credentials bridge.
 * Endpoint: POST /api/v2/verify-credentials
 *
 * Isolated middleware, validation, and canonical credential regressions.
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

    public function test_legacy_employee_password_cannot_bypass_canonical_replacement_or_password_setup(): void
    {
        $employee = Employee::query()->create(['employee_id' => 'BRIDGE-1001', 'name' => 'Bridge Member', 'password' => Hash::make('Original-starter!')]);
        $user = User::factory()->create(['employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'password' => 'Original-starter!', 'must_change_password' => false, 'account_status' => 'active']);
        $user->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $request = fn (string $password) => $this->withToken(self::SHARED_TOKEN)->postJson('/api/v2/verify-credentials', ['employee_id' => $employee->employee_id, 'password' => $password]);
        $request('Original-starter!')->assertOk()->assertJsonPath('role', 'member');
        app(\App\Services\Identity\AccountSecurityService::class)->changePassword($user, Hash::make('New-canonical-password!'), now());
        $request('Original-starter!')->assertUnauthorized();
        $request('New-canonical-password!')->assertOk()->assertJsonPath('member_id', $employee->id);
        $user->givePermissionTo(Permission::findOrCreate('app.bid.admin', 'web'));
        $request('New-canonical-password!')->assertOk()->assertJsonPath('role', 'admin');
        app(\App\Services\Identity\AccountSecurityService::class)->forcePasswordChange($user, now());
        $request('New-canonical-password!')->assertUnauthorized();
    }

    public function test_bridge_requires_exact_canonical_employee_binding_and_active_account(): void
    {
        $employee = Employee::query()->create(['employee_id' => 'BRIDGE-1002', 'name' => 'Bridge Member', 'password' => Hash::make('Canonical-password!')]);
        $user = User::factory()->create(['employee_id' => 'DIFFERENT-ID', 'employee_profile_id' => $employee->id,
            'password' => 'Canonical-password!', 'must_change_password' => false, 'account_status' => 'active']);
        $user->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $request = fn () => $this->withToken(self::SHARED_TOKEN)->postJson('/api/v2/verify-credentials', ['employee_id' => $employee->employee_id, 'password' => 'Canonical-password!']);
        $request()->assertUnauthorized();
        $user->forceFill(['employee_id' => $employee->employee_id, 'account_status' => 'disabled'])->save();
        $request()->assertUnauthorized();
    }
}
