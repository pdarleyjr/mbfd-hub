<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class IssueMemberOnboardingInvitationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['communications.cloudflare.account_id' => str_repeat('a', 32), 'communications.cloudflare.api_token' => 'command-test-token']);
        $now = CarbonImmutable::now();
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32), 'cycle_start' => $now->startOfMonth(), 'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 100, 'provider_daily_used' => 0, 'hub_safe_ceiling' => 100,
            'worker_request_threshold' => 9_000_000, 'worker_cpu_ms_threshold' => 27_000_000, 'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now, 'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0,
        ]);
        Http::fake(fn (Request $request) => Http::response(['success' => true, 'result' => [
            'message_id' => 'command-test-message', 'delivered' => $request->data()['to'], 'queued' => [], 'permanent_bounces' => [], 'suppressed_recipients' => [],
        ]]));
    }

    public function test_default_is_a_non_delivering_dry_run_and_apply_requires_current_backup_evidence(): void
    {
        $member = $this->pending('COMMAND-READY');
        self::assertSame(Command::SUCCESS, Artisan::call('identity:issue-member-onboarding-invitations', [
            '--employee-id' => [$member->employee_id], '--format' => 'json',
        ]));
        self::assertDatabaseCount('member_onboarding_invitations', 0);
        Http::assertNothingSent();

        [$backup, $snapshot] = $this->evidenceFiles();
        try {
            self::assertSame(Command::SUCCESS, Artisan::call('identity:issue-member-onboarding-invitations', [
                '--employee-id' => [$member->employee_id],
                '--apply' => true,
                '--confirm' => 'ISSUE_MEMBER_ONBOARDING_INVITATIONS',
                '--backup' => $backup,
                '--backup-sha256' => hash_file('sha256', $backup),
                '--integrity-snapshot' => $snapshot,
                '--integrity-sha256' => hash_file('sha256', $snapshot),
                '--format' => 'json',
            ]));
        } finally {
            unlink($backup);
            unlink($snapshot);
        }
        self::assertDatabaseHas('member_onboarding_invitations', ['user_id' => $member->id, 'delivery_status' => 'queued']);
        Http::assertSentCount(1);
    }

    public function test_any_missing_or_untrusted_identity_blocks_the_entire_apply_cohort(): void
    {
        $member = $this->pending('COMMAND-BLOCKED');
        [$backup, $snapshot] = $this->evidenceFiles();
        try {
            self::assertSame(Command::FAILURE, Artisan::call('identity:issue-member-onboarding-invitations', [
                '--employee-id' => [$member->employee_id, 'UNKNOWN-EMPLOYEE'],
                '--apply' => true,
                '--confirm' => 'ISSUE_MEMBER_ONBOARDING_INVITATIONS',
                '--backup' => $backup,
                '--backup-sha256' => hash_file('sha256', $backup),
                '--integrity-snapshot' => $snapshot,
                '--integrity-sha256' => hash_file('sha256', $snapshot),
            ]));
        } finally {
            unlink($backup);
            unlink($snapshot);
        }
        self::assertDatabaseCount('member_onboarding_invitations', 0);
        Http::assertNothingSent();
    }

    private function pending(string $employeeId): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId, 'name' => 'Command Member', 'roster_status' => 'active',
            'city_email' => strtolower($employeeId).'@miamibeachfl.gov', 'password' => Hash::make('legacy-password'),
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'email' => 'pending-'.$employeeId.'@canonical.mbfdhub.invalid', 'account_status' => AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => true, 'bootstrap_onboarding_eligible_at' => now(), 'security_version' => 1,
        ]);
    }

    /** @return array{string,string} */
    private function evidenceFiles(): array
    {
        $backup = tempnam(sys_get_temp_dir(), 'mbfd-onboarding-backup-');
        $snapshot = tempnam(sys_get_temp_dir(), 'mbfd-onboarding-integrity-');
        self::assertNotFalse($backup);
        self::assertNotFalse($snapshot);
        file_put_contents($backup, 'PGDMP'.str_repeat('test-dump-data', 20));
        file_put_contents($snapshot, json_encode(app(EstablishedAccountIntegritySnapshot::class)->capture(), JSON_THROW_ON_ERROR));

        return [$backup, $snapshot];
    }
}
