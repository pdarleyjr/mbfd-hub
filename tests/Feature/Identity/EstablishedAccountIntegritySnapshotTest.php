<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EstablishedAccountIntegritySnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_is_redacted_stable_and_excludes_new_bootstrap_cohort(): void
    {
        $employee = $this->employee('SNAP-100', 'established@miamibeachfl.gov');
        $passwordHash = Hash::make('established-private-password');
        $established = User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'email' => 'established@miamibeachfl.gov',
            'password' => $passwordHash,
            'account_status' => AccountStatus::Active,
            'must_change_password' => false,
        ]);
        $established->assignRole(Role::findOrCreate('admin', 'web'));

        $pendingEmployee = $this->employee('SNAP-200', null);
        $pending = User::factory()->create([
            'employee_profile_id' => $pendingEmployee->id,
            'employee_id' => $pendingEmployee->employee_id,
            'account_status' => AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => true,
        ]);

        $snapshot = app(EstablishedAccountIntegritySnapshot::class)->capture();
        self::assertSame(1, $snapshot['established_account_count']);
        self::assertSame($established->id, $snapshot['accounts'][0]['user_id']);
        self::assertSame(hash('sha256', $passwordHash), $snapshot['accounts'][0]['password_hash_fingerprint']);
        self::assertStringNotContainsString($passwordHash, json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertNotContains($pending->id, array_column($snapshot['accounts'], 'user_id'));

        $pending->forceFill(['email' => 'pending@miamibeachfl.gov'])->save();
        $unchanged = app(EstablishedAccountIntegritySnapshot::class)->compare($snapshot, app(EstablishedAccountIntegritySnapshot::class)->capture());
        self::assertSame(0, $unchanged['unexpected_changes']);

        $established->forceFill(['email' => 'changed@miamibeachfl.gov'])->save();
        $changed = app(EstablishedAccountIntegritySnapshot::class)->compare($snapshot, app(EstablishedAccountIntegritySnapshot::class)->capture());
        self::assertSame(1, $changed['unexpected_changes']);
        self::assertSame(1, $changed['email_changes']);
        self::assertSame([$established->id], $changed['changed_user_ids']);
    }

    public function test_command_writes_exclusive_snapshot_and_fails_comparison_on_change(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $baseline = storage_path('framework/testing/established-baseline-'.uniqid().'.json');
        $current = storage_path('framework/testing/established-current-'.uniqid().'.json');

        self::assertSame(Command::SUCCESS, Artisan::call('identity:established-account-integrity', [
            '--write' => $baseline,
        ]), Artisan::output());
        self::assertFileExists($baseline);
        self::assertStringNotContainsString((string) $user->getRawOriginal('password'), (string) file_get_contents($baseline));

        $user->forceFill(['must_change_password' => ! $user->must_change_password])->save();
        $exit = Artisan::call('identity:established-account-integrity', [
            '--write' => $current,
            '--compare' => $baseline,
        ]);
        $output = Artisan::output();
        self::assertSame(Command::FAILURE, $exit, $output);
        self::assertStringContainsString('ESTABLISHED_ACCOUNT_UNEXPECTED_CHANGES=1', $output, $output);
    }

    private function employee(string $employeeId, ?string $cityEmail): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Integrity Snapshot Test',
            'roster_status' => 'active',
            'city_email' => $cityEmail,
            'password' => 'unusable-roster-password',
        ]);
    }
}
