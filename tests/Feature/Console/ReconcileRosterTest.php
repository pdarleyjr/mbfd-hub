<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ReconcileRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_creates_only_missing_ids_and_preserves_existing_profile_fields(): void
    {
        $current = Employee::query()->create([
            'employee_id' => '12345',
            'name' => 'Existing Display Name',
            'rank' => 'Existing Rank',
            'roster_status' => 'departed',
            'password' => 'existing-current-password',
        ]);
        $historical = Employee::query()->create([
            'employee_id' => '99999',
            'name' => 'Historical Person',
            'roster_status' => 'active',
            'password' => 'existing-historical-password',
        ]);
        $historicalUser = User::factory()->create([
            'employee_id' => $historical->employee_id,
            'employee_profile_id' => $historical->id,
            'account_status' => AccountStatus::Active,
        ]);
        $at = CarbonImmutable::now();
        $historicalSession = app(SessionRegistry::class)->register(
            $historicalUser,
            'roster-departure-session',
            SessionContextClass::UnmanagedBrowser,
            $at,
            $at->addHour(),
            $at->addDay(),
        );
        $path = $this->rosterFile();

        try {
            $this->artisan('mbfd:roster-reconcile', ['file' => $path])
                ->assertSuccessful()
                ->expectsOutputToContain('DRY RUN ONLY');
            $this->assertSame('departed', $current->refresh()->roster_status);

            $this->artisan('mbfd:roster-reconcile', ['file' => $path, '--apply' => true])
                ->assertSuccessful();

            $created = Employee::query()->where('employee_id', '54321')->sole();
            $createdHash = $created->getRawOriginal('password');
            $this->assertNotSame('', $createdHash);
            $this->assertFalse(Hash::check('test-owner-approved-bootstrap', $created->getAuthPassword()));
            $this->assertTrue($created->must_change_password);
            $createdUser = $created->user()->sole();
            $this->assertSame(AccountStatus::PendingActivation, $createdUser->account_status);
            $this->assertSame(['member'], $createdUser->getRoleNames()->all());
            $this->assertNull($createdUser->temporary_credential_fingerprint);

            $this->artisan('mbfd:roster-reconcile', ['file' => $path, '--apply' => true])
                ->assertSuccessful();
            $this->assertSame($createdHash, $created->fresh()->getRawOriginal('password'));
        } finally {
            @unlink($path);
        }

        $current->refresh();
        $this->assertSame('Existing Display Name', $current->name);
        $this->assertSame('Existing Rank', $current->rank);
        $this->assertSame('active', $current->roster_status);
        $this->assertSame(AccountStatus::PendingActivation, $current->user()->sole()->account_status);
        $this->assertSame('departed', $historical->refresh()->roster_status);
        $this->assertSame(AccountStatus::Disabled, $historicalUser->fresh()->account_status);
        $this->assertNotNull($historicalSession->fresh()->revoked_at);
        $this->assertDatabaseHas('employees', [
            'employee_id' => '54321',
            'name' => 'New Person',
            'rank' => 'Firefighter',
            'roster_status' => 'active',
        ]);
        $this->assertSame(3, Employee::query()->count());
    }

    public function test_reactivation_preserves_a_disabled_canonical_user_without_enabling_or_escalating_it(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => '12345',
            'name' => 'Returning Person',
            'roster_status' => 'departed',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Disabled,
        ]);
        $user->assignRole(Role::findOrCreate('member', 'web'));
        $before = [$user->id, $user->password, $user->security_version, $user->getRoleNames()->all()];
        $path = $this->rosterFile();

        try {
            $this->artisan('mbfd:roster-reconcile', ['file' => $path, '--apply' => true])->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $this->assertSame('active', $employee->fresh()->roster_status);
        $this->assertSame(AccountStatus::Disabled, $user->fresh()->account_status);
        $this->assertSame($before, [$user->id, $user->fresh()->password, $user->fresh()->security_version, $user->fresh()->getRoleNames()->all()]);
    }

    public function test_json_dry_run_reports_exact_set_differences_without_mutation(): void
    {
        Employee::query()->create([
            'employee_id' => '12345',
            'name' => 'Existing Person',
            'roster_status' => 'active',
            'password' => 'existing-current-password',
        ]);
        Employee::query()->create([
            'employee_id' => '99999',
            'name' => 'Historical Person',
            'roster_status' => 'active',
            'password' => 'existing-historical-password',
        ]);
        $path = $this->rosterFile();

        try {
            $this->assertSame(0, Artisan::call('mbfd:roster-reconcile', ['file' => $path, '--json' => true]));
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(['12345'], $report['current_match']);
            $this->assertSame(['54321'], $report['current_missing_from_db']);
            $this->assertSame(['99999'], $report['db_not_in_current_roster']);
            $this->assertSame([], $report['duplicate_collision']);
            $this->assertFalse($report['applied']);
        } finally {
            @unlink($path);
        }

        $this->assertSame(2, Employee::query()->count());
        $this->assertSame('active', Employee::query()->where('employee_id', '99999')->sole()->roster_status);
    }

    private function rosterFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-roster-');
        file_put_contents($path, <<<'HTML'
        <table><tr><td><table>
        <tr><th>Name</th><th>Emp ID</th><th>Unit</th><th>Position</th></tr>
        <tr><td>PERSON, CURRENT</td><td>12345</td><td>Engine 1</td><td>Lieutenant</td></tr>
        <tr><td>PERSON, NEW</td><td>54321</td><td>Engine 2</td><td>Firefighter</td></tr>
        </table></td></tr></table>
        HTML);

        return $path;
    }
}
