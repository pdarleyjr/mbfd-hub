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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ImportAuthoritativeCityEmailRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_read_only_and_apply_binds_only_pending_accounts_without_sending_email(): void
    {
        Http::fake();
        $pending = $this->member('ROSTER-100', AccountStatus::PendingActivation);
        $established = $this->member('ROSTER-200', AccountStatus::Active);
        $oldEstablishedEmail = $established->email;
        $csv = $this->csv([
            ['ROSTER-100', 'Pending.Member@MiamiBeachFL.gov'],
            ['ROSTER-200', 'established.new@miamibeachfl.gov'],
            ['ROSTER-300', ''],
        ]);

        try {
            self::assertSame(Command::SUCCESS, Artisan::call('identity:import-authoritative-city-emails', [
                'file' => $csv, '--format' => 'json',
            ]));
            self::assertNull($pending->employeeProfile->fresh()->city_email);
            self::assertSame($oldEstablishedEmail, $established->fresh()->email);
            self::assertDatabaseCount('member_onboarding_roster_bindings', 0);

            [$backup, $snapshot] = $this->evidenceFiles();
            try {
                self::assertSame(Command::SUCCESS, Artisan::call('identity:import-authoritative-city-emails', [
                    'file' => $csv,
                    '--apply' => true,
                    '--confirm' => 'IMPORT_AUTHORITATIVE_CITY_EMAIL_ROSTER',
                    '--source-sha256' => hash_file('sha256', $csv),
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

            self::assertSame('pending.member@miamibeachfl.gov', $pending->fresh()->email);
            self::assertSame('pending.member@miamibeachfl.gov', $pending->employeeProfile->fresh()->city_email);
            self::assertSame(AccountStatus::PendingActivation->value, $pending->fresh()->getRawOriginal('account_status'));
            self::assertSame($oldEstablishedEmail, $established->fresh()->email);
            self::assertNull($established->employeeProfile->fresh()->city_email);
            self::assertDatabaseHas('member_onboarding_roster_bindings', [
                'employee_profile_id' => $pending->employee_profile_id,
                'employee_id' => 'ROSTER-100',
                'city_email' => 'pending.member@miamibeachfl.gov',
                'source_sha256' => hash_file('sha256', $csv),
            ]);
            self::assertDatabaseCount('member_onboarding_roster_bindings', 1);
            Http::assertNothingSent();
        } finally {
            unlink($csv);
        }
    }

    public function test_conflicts_fail_closed_before_any_roster_binding_is_written(): void
    {
        Http::fake();
        $pending = $this->member('ROSTER-400', AccountStatus::PendingActivation);
        $other = $this->member('ROSTER-500', AccountStatus::PendingActivation);
        $other->employeeProfile->forceFill(['city_email' => 'taken@miamibeachfl.gov'])->save();
        $csv = $this->csv([
            ['ROSTER-400', 'taken@miamibeachfl.gov'],
            ['ROSTER-400', 'another@miamibeachfl.gov'],
        ]);
        [$backup, $snapshot] = $this->evidenceFiles();

        try {
            self::assertSame(Command::FAILURE, Artisan::call('identity:import-authoritative-city-emails', [
                'file' => $csv,
                '--apply' => true,
                '--confirm' => 'IMPORT_AUTHORITATIVE_CITY_EMAIL_ROSTER',
                '--source-sha256' => hash_file('sha256', $csv),
                '--backup' => $backup,
                '--backup-sha256' => hash_file('sha256', $backup),
                '--integrity-snapshot' => $snapshot,
                '--integrity-sha256' => hash_file('sha256', $snapshot),
            ]));
            self::assertNull($pending->employeeProfile->fresh()->city_email);
            self::assertDatabaseCount('member_onboarding_roster_bindings', 0);
            Http::assertNothingSent();
        } finally {
            unlink($csv);
            unlink($backup);
            unlink($snapshot);
        }
    }

    public function test_city_email_collision_blocks_apply_even_with_unique_roster_rows(): void
    {
        Http::fake();
        $pending = $this->member('ROSTER-410', AccountStatus::PendingActivation);
        $other = $this->member('ROSTER-510', AccountStatus::PendingActivation);
        $other->employeeProfile->forceFill(['city_email' => 'taken@miamibeachfl.gov'])->save();
        $csv = $this->csv([['ROSTER-410', 'taken@miamibeachfl.gov']]);
        [$backup, $snapshot] = $this->evidenceFiles();

        try {
            self::assertSame(Command::FAILURE, Artisan::call('identity:import-authoritative-city-emails', [
                'file' => $csv,
                '--apply' => true,
                '--confirm' => 'IMPORT_AUTHORITATIVE_CITY_EMAIL_ROSTER',
                '--source-sha256' => hash_file('sha256', $csv),
                '--backup' => $backup,
                '--backup-sha256' => hash_file('sha256', $backup),
                '--integrity-snapshot' => $snapshot,
                '--integrity-sha256' => hash_file('sha256', $snapshot),
            ]));
            self::assertNull($pending->employeeProfile->fresh()->city_email);
            self::assertDatabaseCount('member_onboarding_roster_bindings', 0);
            Http::assertNothingSent();
        } finally {
            unlink($csv);
            unlink($backup);
            unlink($snapshot);
        }
    }

    public function test_apply_rejects_missing_or_tampered_backup_and_integrity_evidence(): void
    {
        $pending = $this->member('ROSTER-600', AccountStatus::PendingActivation);
        $csv = $this->csv([['ROSTER-600', 'roster.member@miamibeachfl.gov']]);
        try {
            self::assertSame(Command::FAILURE, Artisan::call('identity:import-authoritative-city-emails', [
                'file' => $csv, '--apply' => true,
            ]));
            self::assertNull($pending->employeeProfile->fresh()->city_email);
            self::assertDatabaseCount('member_onboarding_roster_bindings', 0);
        } finally {
            unlink($csv);
        }
    }

    private function member(string $employeeId, AccountStatus $status): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId, 'name' => 'Roster Member', 'roster_status' => 'active',
            'password' => Hash::make('legacy-password'),
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employeeId,
            'email' => strtolower($employeeId).'@canonical.mbfdhub.invalid', 'account_status' => $status,
            'bootstrap_onboarding_eligible' => $status === AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible_at' => $status === AccountStatus::PendingActivation ? now() : null,
            'security_version' => 1,
        ]);
    }

    /** @param array<int, array{string, string}> $rows */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-authoritative-roster-');
        self::assertNotFalse($path);
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fputcsv($handle, ['Employee ID', 'Email Address']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    /** @return array{string,string} */
    private function evidenceFiles(): array
    {
        $backup = tempnam(sys_get_temp_dir(), 'mbfd-roster-backup-');
        $snapshot = tempnam(sys_get_temp_dir(), 'mbfd-roster-integrity-');
        self::assertNotFalse($backup);
        self::assertNotFalse($snapshot);
        file_put_contents($backup, 'PGDMP'.str_repeat('test-dump-data', 20));
        file_put_contents($snapshot, json_encode(app(EstablishedAccountIntegritySnapshot::class)->capture(), JSON_THROW_ON_ERROR));

        return [$backup, $snapshot];
    }
}
