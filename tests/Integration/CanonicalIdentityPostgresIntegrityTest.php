<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres')]
final class CanonicalIdentityPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_enforces_canonical_identity_constraints_without_auto_linking_legacy_ids(): void
    {
        $this->requireDisposablePostgres();

        $employee = Employee::query()->create([
            'employee_id' => '10010',
            'name' => 'PostgreSQL Identity Employee',
            'rank' => 'Firefighter',
            'password' => 'password',
            'must_change_password' => false,
        ]);
        $unlinked = User::factory()->create(['employee_id' => '10010']);
        $linked = User::factory()->create(['employee_profile_id' => $employee->id]);

        $this->assertNull($unlinked->employee_profile_id);
        $this->assertSame($employee->id, $linked->employee_profile_id);

        try {
            User::factory()->create(['employee_profile_id' => $employee->id]);
            $this->fail('The unique User-to-Employee constraint did not reject a duplicate link.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            User::factory()->create(['account_status' => 'unknown']);
            $this->fail('The account-status check constraint did not reject an unknown value.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(AccountStatus::PendingActivation, $unlinked->account_status);
    }

    private function requireDisposablePostgres(): void
    {
        $allowed = getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('DISPOSABLE_POSTGRES_HOST') === '127.0.0.1'
            && str_starts_with((string) getenv('DISPOSABLE_POSTGRES_DATABASE'), 'mbfd_hub_test_');

        if ($allowed) {
            return;
        }

        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            $this->fail('PostgreSQL integration tests require the explicitly configured loopback disposable database.');
        }

        $this->markTestSkipped('This regression requires the explicitly configured disposable PostgreSQL database.');
    }
}
