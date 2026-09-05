<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Bid;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private const FEDERATION_TOKEN = 'test-bid-federation-secret-do-not-use-in-prod';

    private const READER_TOKEN = 'test-bid-reader-secret-do-not-use-in-prod';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bid.federation_token', self::FEDERATION_TOKEN);
        config()->set('services.bid.reader_token', self::READER_TOKEN);
        config()->set('services.bid.authorization.issuer', 'https://www.mbfdhub.com');
    }

    public function test_exact_current_canonical_identity_revalidates(): void
    {
        $user = $this->linkedUser();

        $this->revalidate($user)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'issuer' => 'https://www.mbfdhub.com',
                'audience' => 'bid',
                'hub_user_id' => $user->id,
                'security_version' => 1,
                'member_id' => $user->employeeProfile->id,
                'employee_id' => $user->employeeProfile->employee_id,
                'role' => 'member',
            ]);
    }

    public function test_revalidation_reflects_current_explicit_admin_panel_entitlement(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = $this->linkedUser();

        $this->revalidate($user)->assertOk()->assertJsonPath('role', 'member');

        $user->assignRole('admin');
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->revalidate($user)->assertOk()->assertJsonPath('role', 'admin');

        $user->revokePermissionTo('admin.access');
        $this->revalidate($user)->assertOk()->assertJsonPath('role', 'member');
    }

    public function test_revalidation_fails_closed_for_missing_or_disabled_user(): void
    {
        $disabled = $this->linkedUser('BID-DISABLED');
        $disabled->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $this->revalidate($disabled)->assertUnauthorized()->assertExactJson(['error' => 'invalid_identity']);

        $missing = $this->linkedUser('BID-MISSING');
        $payload = $this->identityPayload($missing);
        $missing->delete();
        $this->postRevalidation($payload)
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'invalid_identity']);
    }

    public function test_revalidation_denies_security_version_and_member_mismatches(): void
    {
        $user = $this->linkedUser();

        $this->postRevalidation([
            ...$this->identityPayload($user),
            'security_version' => 2,
        ])->assertUnauthorized()->assertExactJson(['error' => 'invalid_identity']);

        $this->postRevalidation([
            ...$this->identityPayload($user),
            'member_id' => $user->employeeProfile->id + 1,
        ])->assertUnauthorized()->assertExactJson(['error' => 'invalid_identity']);
    }

    public function test_revalidation_denies_missing_or_changed_employee_linkage(): void
    {
        $unlinked = $this->linkedUser('BID-UNLINKED');
        $unlinkedPayload = $this->identityPayload($unlinked);
        $unlinked->forceFill(['employee_profile_id' => null])->save();
        $this->postRevalidation($unlinkedPayload)
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'invalid_identity']);

        $relinked = $this->linkedUser('BID-RELINKED');
        $relinkedPayload = $this->identityPayload($relinked);
        $replacement = Employee::query()->create([
            'employee_id' => 'BID-REPLACEMENT',
            'name' => 'Replacement Bid Member',
            'rank' => 'Captain',
            'password' => Hash::make('replacement-test-credential'),
            'must_change_password' => false,
        ]);
        $relinked->forceFill(['employee_profile_id' => $replacement->id])->save();
        $this->postRevalidation($relinkedPayload)
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'invalid_identity']);
    }

    public function test_revalidation_rejects_caller_supplied_identity_and_authorization_fields(): void
    {
        $user = $this->linkedUser();

        foreach (['name', 'email', 'rank', 'role', 'password'] as $field) {
            $this->postRevalidation([
                ...$this->identityPayload($user),
                $field => 'caller-controlled',
            ])->assertUnprocessable();
        }
    }

    public function test_federation_and_legacy_credentials_are_not_interchangeable(): void
    {
        $user = $this->linkedUser();
        $payload = $this->identityPayload($user);

        $this->postJson('/api/v2/bid/auth/revalidate', $payload)->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
            ->postJson('/api/v2/bid/auth/revalidate', $payload)
            ->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer '.self::READER_TOKEN])
            ->postJson('/api/v2/bid/auth/revalidate', $payload)
            ->assertUnauthorized();

        $this->withHeaders(['Authorization' => 'Bearer '.self::FEDERATION_TOKEN])
            ->postJson('/api/v2/verify-credentials', [
                'employee_id' => $user->employeeProfile->employee_id,
                'password' => 'legacy-employee-password',
            ])->assertUnauthorized();
    }

    public function test_revalidation_fails_closed_when_federation_is_unconfigured(): void
    {
        $user = $this->linkedUser();
        config()->set('services.bid.federation_token', null);

        $this->postRevalidation($this->identityPayload($user))
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'bid_federation_unavailable']);
    }

    public function test_revalidation_fails_closed_when_authorization_resolution_is_unavailable(): void
    {
        $user = $this->linkedUser();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'model_has_roles')) {
                throw new RuntimeException('Simulated authorization store failure.');
            }
        });

        $this->revalidate($user)
            ->assertServiceUnavailable()
            ->assertExactJson(['error' => 'authorization_unavailable']);
    }

    private function linkedUser(string $employeeId = 'BID-1001'): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Canonical Bid Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('legacy-employee-password'),
            'must_change_password' => false,
        ]);

        $user = User::factory()->create([
            'employee_id' => $employeeId,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('canonical-user-password'),
            'security_version' => 1,
        ])->load('employeeProfile');
        $user->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));

        return $user;
    }

    private function revalidate(User $user)
    {
        return $this->postRevalidation($this->identityPayload($user));
    }

    /** @param array<string, mixed> $payload */
    private function postRevalidation(array $payload)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::FEDERATION_TOKEN])
            ->postJson('/api/v2/bid/auth/revalidate', $payload);
    }

    /** @return array{hub_user_id: int, security_version: int, member_id: int} */
    private function identityPayload(User $user): array
    {
        return [
            'hub_user_id' => (int) $user->getKey(),
            'security_version' => (int) $user->security_version,
            'member_id' => (int) $user->employeeProfile->getKey(),
        ];
    }
}
