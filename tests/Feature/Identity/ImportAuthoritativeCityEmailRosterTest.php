<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use App\Models\UserIdentityLink;
use App\Services\Identity\AuthoritativeCityEmailRosterImporter;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    public function test_fill_missing_repairs_an_established_account_without_changing_security_or_omitted_bindings(): void
    {
        Http::fake();
        $user = $this->member('19965', AccountStatus::Active);
        $user->forceFill(['last_login_at' => now()->subMonth()])->save();
        $user->assignRole(Role::findOrCreate('member', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('apparatus.view', 'web'));
        AuthenticationSession::factory()->create(['user_id' => $user->id]);
        $omitted = $this->member('OMITTED', AccountStatus::PendingActivation);
        $binding = MemberOnboardingRosterBinding::query()->create([
            'employee_profile_id' => $omitted->employee_profile_id, 'employee_id' => 'OMITTED',
            'city_email' => 'omitted@miamibeachfl.gov', 'source_sha256' => str_repeat('a', 64), 'approved_at' => now(),
        ]);
        $before = app(EstablishedAccountIntegritySnapshot::class)->capture();
        $profile = $user->employeeProfile->getAttributes();
        $counts = [User::query()->count(), Employee::query()->count()];
        $csv = $this->csv([["\u{00A0}19965\u{00A0}", 'DannyDiaz@miamibeachfl.gov', 'Exact']], true);
        try {
            self::assertSame(Command::SUCCESS, Artisan::call('identity:import-authoritative-city-emails', ['file' => $csv, '--fill-missing' => true, '--format' => 'json']));
            self::assertNull($user->employeeProfile->fresh()->city_email);
            self::assertSame(Command::SUCCESS, $this->applyMissing($csv));
            $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('preserved', $output['established_account_integrity']);
            self::assertSame(1, $output['applied']['bound']);
            self::assertSame('dannydiaz@miamibeachfl.gov', $user->fresh()->email);
            self::assertSame('dannydiaz@miamibeachfl.gov', $user->employeeProfile->fresh()->city_email);
            $after = app(EstablishedAccountIntegritySnapshot::class)->capture();
            $expected = $before['accounts'][0];
            $actual = $after['accounts'][0];
            foreach (['user_email', 'employee_city_email', 'email_fingerprint'] as $field) {
                unset($expected[$field], $actual[$field]);
            }
            self::assertSame($expected, $actual);
            $profileAfter = $user->employeeProfile->fresh()->getAttributes();
            unset($profile['city_email'], $profile['updated_at'], $profileAfter['city_email'], $profileAfter['updated_at']);
            self::assertSame($profile, $profileAfter);
            self::assertSame($counts, [User::query()->count(), Employee::query()->count()]);
            self::assertEquals($binding->getAttributes(), $binding->fresh()->getAttributes());
            self::assertSame(Command::SUCCESS, $this->applyMissing($csv));
            self::assertSame(0, json_decode(Artisan::output(), true)['applied']['bound']);
            Http::assertNothingSent();
        } finally {
            unlink($csv);
        }
    }

    public function test_fill_missing_preserves_existing_real_addresses_and_skips_review_and_unknown_rows(): void
    {
        Http::fake();
        $existing = $this->member('EXISTING', AccountStatus::Active);
        $existing->forceFill(['email' => 'existing@miamibeachfl.gov'])->save();
        $existing->employeeProfile->forceFill(['city_email' => 'existing@miamibeachfl.gov'])->save();
        $legacy = $this->member('LEGACY', AccountStatus::Active);
        $legacy->forceFill(['email' => 'legacy@miamibeachfl.gov'])->save();
        $legacy->employeeProfile->forceFill(['city_email' => 'legacy@miamibeachfl.gov'])->save();
        $review = $this->member('REVIEW', AccountStatus::PendingActivation);
        Employee::query()->create(['employee_id' => 'NOLOGIN', 'name' => 'No Login', 'roster_status' => 'active']);
        $csv = $this->csv([
            ['EXISTING', 'stale@miamibeachfl.gov', 'Exact'],
            ['LEGACY', 'legacy@miamibeachfl.gov', 'Exact'],
            ['REVIEW', 'review@miamibeachfl.gov', 'Probable surname change — review'],
            ['UNKNOWN', 'unknown@miamibeachfl.gov', 'Exact'],
            ['NOLOGIN', 'nologin@miamibeachfl.gov', 'Exact'],
            ['UNRESOLVED', '', 'Unresolved'],
        ], true);
        try {
            self::assertSame(Command::SUCCESS, $this->applyMissing($csv));
            self::assertSame('existing@miamibeachfl.gov', $existing->fresh()->email);
            self::assertNull($review->employeeProfile->fresh()->city_email);
            self::assertDatabaseCount('users', 3);
            self::assertDatabaseCount('employees', 4);
            self::assertDatabaseCount('member_onboarding_roster_bindings', 0);
            Http::assertNothingSent();
        } finally {
            unlink($csv);
        }
    }

    public function test_fill_missing_can_complete_an_identical_verified_address_without_changing_mailbox_proof(): void
    {
        $user = $this->member('SAME', AccountStatus::Active);
        $user->forceFill(['email' => 'same@miamibeachfl.gov'])->save();
        $user->forceFill(['email_verified_at' => now()->subDay()])->save();
        $verifiedAt = $user->email_verified_at;
        $csv = $this->csv([['SAME', 'same@miamibeachfl.gov', 'Exact']], true);
        try {
            self::assertSame(Command::SUCCESS, $this->applyMissing($csv));
            self::assertSame('same@miamibeachfl.gov', $user->employeeProfile->fresh()->city_email);
            self::assertTrue($verifiedAt->equalTo($user->fresh()->email_verified_at));
        } finally {
            unlink($csv);
        }
    }

    public function test_fill_missing_conflicts_fail_before_any_email_is_changed(): void
    {
        $ready = $this->member('READY', AccountStatus::Active);
        $conflict = $this->member('CONFLICT', AccountStatus::Active);
        $conflict->forceFill(['email' => 'existing@miamibeachfl.gov'])->save();
        $csv = $this->csv([['READY', 'ready@miamibeachfl.gov', 'Exact'], ['CONFLICT', 'different@miamibeachfl.gov', 'Exact']], true);
        try {
            self::assertSame(Command::FAILURE, $this->applyMissing($csv));
            self::assertNull($ready->employeeProfile->fresh()->city_email);
            self::assertSame('existing@miamibeachfl.gov', $conflict->fresh()->email);
        } finally {
            unlink($csv);
        }
    }

    public function test_fill_missing_adds_only_absent_bindings_for_exact_existing_addresses(): void
    {
        $user = $this->member('BINDING', AccountStatus::Active);
        $user->forceFill(['email' => 'binding@miamibeachfl.gov', 'bootstrap_onboarding_completed_at' => now()->subDay()])->save();
        $user->employeeProfile->forceFill(['city_email' => 'binding@miamibeachfl.gov'])->save();
        $before = app(EstablishedAccountIntegritySnapshot::class)->capture();
        $csv = $this->csv([['BINDING', 'binding@miamibeachfl.gov', 'Exact']], true);
        try {
            self::assertSame(Command::SUCCESS, $this->applyMissing($csv));
            self::assertDatabaseHas('member_onboarding_roster_bindings', [
                'employee_profile_id' => $user->employee_profile_id, 'employee_id' => 'BINDING',
                'city_email' => 'binding@miamibeachfl.gov', 'source_sha256' => hash_file('sha256', $csv),
            ]);
            self::assertSame(0, app(EstablishedAccountIntegritySnapshot::class)->compare($before, app(EstablishedAccountIntegritySnapshot::class)->capture())['unexpected_changes']);
            $binding = MemberOnboardingRosterBinding::query()->sole();
            $binding->forceFill(['city_email' => 'different@miamibeachfl.gov'])->save();
            self::assertSame(Command::FAILURE, $this->applyMissing($csv));
            self::assertSame('different@miamibeachfl.gov', $binding->fresh()->city_email);
        } finally {
            unlink($csv);
        }
    }

    public function test_fill_missing_rechecks_identity_and_existing_address_after_preview(): void
    {
        $user = $this->member('RACE', AccountStatus::Active);
        $csv = $this->csv([['RACE', 'race@miamibeachfl.gov', 'Exact']], true);
        try {
            $importer = app(AuthoritativeCityEmailRosterImporter::class);
            $preview = $importer->preview($csv, true);
            $user->employeeProfile->forceFill(['city_email' => 'changed@miamibeachfl.gov'])->save();
            $this->expectException(\RuntimeException::class);
            $importer->apply($preview);
        } finally {
            unlink($csv);
        }
    }

    public function test_fill_missing_requires_classification_and_all_existing_apply_evidence(): void
    {
        $user = $this->member('EVIDENCE', AccountStatus::Active);
        $csv = $this->csv([['EVIDENCE', 'evidence@miamibeachfl.gov', 'Exact']], true);
        $unclassified = $this->csv([['EVIDENCE', 'evidence@miamibeachfl.gov']]);
        try {
            self::assertSame(Command::FAILURE, Artisan::call('identity:import-authoritative-city-emails', ['file' => $unclassified, '--fill-missing' => true]));
            self::assertSame(Command::FAILURE, Artisan::call('identity:import-authoritative-city-emails', ['file' => $csv, '--fill-missing' => true, '--apply' => true]));
            self::assertSame(Command::FAILURE, $this->applyMissing($csv, ['--source-sha256' => str_repeat('0', 64)]));
            self::assertNull($user->employeeProfile->fresh()->city_email);
        } finally {
            unlink($csv);
            unlink($unclassified);
        }
    }

    public function test_fill_missing_refuses_verified_synthetic_state_and_does_not_synchronize_upstream_accounts(): void
    {
        Http::fake();
        $verified = $this->member('VERIFIED', AccountStatus::Active);
        $verified->forceFill(['email_verified_at' => now()])->save();
        $upstream = $this->member('UPSTREAM', AccountStatus::Active);
        UserIdentityLink::query()->create(['user_id' => $upstream->id, 'provider' => 'authentik', 'subject' => 'upstream-subject']);
        $csv = $this->csv([['VERIFIED', 'verified@miamibeachfl.gov', 'Exact'], ['UPSTREAM', 'upstream@miamibeachfl.gov', 'Exact']], true);
        try {
            self::assertSame(Command::FAILURE, $this->applyMissing($csv));
            self::assertNull($verified->employeeProfile->fresh()->city_email);
            self::assertNull($upstream->employeeProfile->fresh()->city_email);
            self::assertDatabaseCount('identity_synchronizations', 0);
            Http::assertNothingSent();
        } finally {
            unlink($csv);
        }
    }

    public function test_fill_missing_duplicate_or_taken_city_email_cannot_partially_apply(): void
    {
        $ready = $this->member('UNIQUE', AccountStatus::Active);
        $other = $this->member('TAKEN', AccountStatus::Active);
        $other->employeeProfile->forceFill(['city_email' => 'taken@miamibeachfl.gov'])->save();
        foreach ([
            [['UNIQUE', 'first@miamibeachfl.gov', 'Exact'], ['UNIQUE', 'second@miamibeachfl.gov', 'Exact']],
            [['UNIQUE', 'taken@miamibeachfl.gov', 'Exact']],
        ] as $rows) {
            $csv = $this->csv($rows, true);
            try {
                self::assertSame(Command::FAILURE, $this->applyMissing($csv));
                self::assertNull($ready->employeeProfile->fresh()->city_email);
                self::assertDatabaseCount('member_onboarding_roster_bindings', 0);
            } finally {
                unlink($csv);
            }
        }
    }

    private function applyMissing(string $csv, array $overrides = []): int
    {
        [$backup, $snapshot] = $this->evidenceFiles();
        try {
            return Artisan::call('identity:import-authoritative-city-emails', array_replace([
                'file' => $csv, '--fill-missing' => true, '--apply' => true,
                '--confirm' => 'IMPORT_AUTHORITATIVE_CITY_EMAIL_ROSTER', '--source-sha256' => hash_file('sha256', $csv),
                '--backup' => $backup, '--backup-sha256' => hash_file('sha256', $backup),
                '--integrity-snapshot' => $snapshot, '--integrity-sha256' => hash_file('sha256', $snapshot), '--format' => 'json',
            ], $overrides));
        } finally {
            unlink($backup);
            unlink($snapshot);
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
            'email_verified_at' => null,
            'bootstrap_onboarding_eligible' => $status === AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible_at' => $status === AccountStatus::PendingActivation ? now() : null,
            'security_version' => 1,
        ]);
    }

    /** @param array<int, array{string, string}> $rows */
    private function csv(array $rows, bool $classified = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-authoritative-roster-');
        self::assertNotFalse($path);
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fputcsv($handle, $classified ? ['Employee ID', 'Email Address', 'Email Match Status'] : ['Employee ID', 'Email Address']);
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
