<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MemberBootstrapCohortInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_manifest_initializes_only_the_proven_unestablished_cohort_idempotently(): void
    {
        $establishedEmployee = $this->employee('COHORT-EST');
        $establishedHash = Hash::make('established-private-password');
        $established = User::factory()->create([
            'employee_profile_id' => $establishedEmployee->id,
            'employee_id' => $establishedEmployee->employee_id,
            'email' => 'established@miamibeachfl.gov',
            'password' => $establishedHash,
            'account_status' => AccountStatus::Active,
            'must_change_password' => false,
        ]);
        $pending = $this->pendingPlaceholder('COHORT-NEW');
        $manifest = $this->manifest([$pending]);

        $arguments = [
            '--manifest' => $manifest,
            '--confirm' => 'INITIALIZE_PROVEN_MEMBER_BOOTSTRAP_COHORT',
        ];
        $status = Artisan::call('identity:initialize-member-bootstrap-cohort', $arguments);
        $output = Artisan::output();
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('BOOTSTRAP_COHORT_INITIALIZED=1', $output);
        self::assertTrue($pending->fresh()->bootstrap_onboarding_eligible);
        self::assertNotNull($pending->fresh()->bootstrap_onboarding_eligible_at);

        $status = Artisan::call('identity:initialize-member-bootstrap-cohort', $arguments);
        $output = Artisan::output();
        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('BOOTSTRAP_COHORT_ALREADY_ELIGIBLE=1', $output);
        self::assertSame($establishedHash, $established->fresh()->getRawOriginal('password'));
        self::assertFalse($established->fresh()->bootstrap_onboarding_eligible);
    }

    public function test_manifest_fingerprint_or_prior_authentication_artifacts_fail_closed_without_partial_updates(): void
    {
        $first = $this->pendingPlaceholder('COHORT-BAD-1');
        $second = $this->pendingPlaceholder('COHORT-BAD-2');
        AuthenticationSession::factory()->for($second)->create([
            'security_version' => $second->security_version,
        ]);
        $manifest = $this->manifest([$first, $second]);

        self::assertSame(Command::FAILURE, Artisan::call('identity:initialize-member-bootstrap-cohort', [
            '--manifest' => $manifest,
            '--confirm' => 'INITIALIZE_PROVEN_MEMBER_BOOTSTRAP_COHORT',
        ]), Artisan::output());
        self::assertFalse($first->fresh()->bootstrap_onboarding_eligible);
        self::assertFalse($second->fresh()->bootstrap_onboarding_eligible);

        AuthenticationSession::query()->delete();
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => $second->getMorphClass(),
            'tokenable_id' => $second->id,
            'name' => 'cohort-test',
            'token' => hash('sha256', 'cohort-test-token'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::assertSame(Command::FAILURE, Artisan::call('identity:initialize-member-bootstrap-cohort', [
            '--manifest' => $manifest,
            '--confirm' => 'INITIALIZE_PROVEN_MEMBER_BOOTSTRAP_COHORT',
        ]), Artisan::output());
        self::assertFalse($first->fresh()->bootstrap_onboarding_eligible);
        self::assertFalse($second->fresh()->bootstrap_onboarding_eligible);

        DB::table('personal_access_tokens')->delete();
        $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $decoded['members'][1]['password_hash_fingerprint'] = str_repeat('0', 64);
        file_put_contents($manifest, json_encode($decoded, JSON_THROW_ON_ERROR));

        self::assertSame(Command::FAILURE, Artisan::call('identity:initialize-member-bootstrap-cohort', [
            '--manifest' => $manifest,
            '--confirm' => 'INITIALIZE_PROVEN_MEMBER_BOOTSTRAP_COHORT',
        ]), Artisan::output());
        self::assertFalse($first->fresh()->bootstrap_onboarding_eligible);
        self::assertFalse($second->fresh()->bootstrap_onboarding_eligible);
    }

    public function test_integrity_comparison_allows_only_manifest_proven_cohort_transition(): void
    {
        $establishedEmployee = $this->employee('COHORT-SNAP-EST');
        $established = User::factory()->create([
            'employee_profile_id' => $establishedEmployee->id,
            'employee_id' => $establishedEmployee->employee_id,
            'account_status' => AccountStatus::Active,
        ]);
        $pending = $this->pendingPlaceholder('COHORT-SNAP-NEW');
        $snapshots = app(EstablishedAccountIntegritySnapshot::class);
        $before = $snapshots->capture();

        self::assertSame(Command::SUCCESS, Artisan::call('identity:initialize-member-bootstrap-cohort', [
            '--manifest' => $this->manifest([$pending]),
            '--confirm' => 'INITIALIZE_PROVEN_MEMBER_BOOTSTRAP_COHORT',
        ]), Artisan::output());
        $after = $snapshots->capture();

        self::assertSame(1, $snapshots->compare($before, $after)['unexpected_changes']);
        self::assertSame(0, $snapshots->compare($before, $after, [$pending->id])['unexpected_changes']);

        $established->forceFill(['email' => 'unexpected@miamibeachfl.gov'])->save();
        $changed = $snapshots->compare($before, $snapshots->capture(), [$pending->id]);
        self::assertSame(1, $changed['unexpected_changes']);
        self::assertSame([$established->id], $changed['changed_user_ids']);
    }

    private function pendingPlaceholder(string $employeeId): User
    {
        $employee = $this->employee($employeeId);

        return User::factory()->unverified()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'email' => "employee-{$employee->id}@canonical.mbfdhub.invalid",
            'password' => Hash::make('unrecoverable-'.bin2hex(random_bytes(16))),
            'account_status' => AccountStatus::PendingActivation,
            'must_change_password' => true,
            'password_changed_at' => null,
            'temporary_credential_fingerprint' => null,
            'last_login_at' => null,
            'remember_token' => null,
            'bootstrap_onboarding_eligible' => false,
            'bootstrap_onboarding_eligible_at' => null,
            'bootstrap_onboarding_completed_at' => null,
        ]);
    }

    /** @param list<User> $users */
    private function manifest(array $users): string
    {
        $path = storage_path('framework/testing/member-bootstrap-cohort-'.bin2hex(random_bytes(6)).'.json');
        $members = collect($users)->sortBy('id')->map(static fn (User $user): array => [
            'user_id' => $user->id,
            'employee_profile_id' => $user->employee_profile_id,
            'created_at' => $user->created_at?->toIso8601String(),
            'password_hash_fingerprint' => hash('sha256', (string) $user->getRawOriginal('password')),
        ])->values()->all();
        file_put_contents($path, json_encode([
            'schema' => 'mbfd-member-bootstrap-cohort-v1',
            'source_backup_file' => 'mbfd-hub-pre-activation-20260910T123707Z-f3a1f7a3a0625473696f77ca3a02bfc7c4de07d3.dump',
            'source_backup_sha256' => str_repeat('a', 64),
            'expected_count' => count($members),
            'members' => $members,
        ], JSON_THROW_ON_ERROR));
        chmod($path, 0600);

        return $path;
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Bootstrap Cohort Test',
            'roster_status' => 'active',
            'password' => 'unusable-roster-password',
        ]);
    }
}
