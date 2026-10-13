<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\MemberActivationVerifier;
use App\Models\User;
use App\Services\Identity\MemberSelfServiceActivation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ImportMemberActivationVerifiersTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'activation-records-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_dry_run_reports_reconciliation_without_storing_or_printing_proofs(): void
    {
        $this->member('IMPORT-A');
        $this->csv([['IMPORT-A', '1985-06-14', '(305) 555-0142'], ['IMPORT-MISSING', '1990-01-02', '3055550143']]);

        $result = $this->run_(['--format' => 'json']);

        self::assertSame(0, $result['exit']);
        self::assertSame('dry_run', $result['json']['mode']);
        self::assertSame(['employee_not_found' => 1, 'ready' => 1], $result['json']['statuses']);
        self::assertSame([['employee_id' => 'IMPORT-MISSING', 'status' => 'employee_not_found']], $result['json']['exceptions']);
        self::assertStringNotContainsString('1985', $result['output']);
        self::assertStringNotContainsString('0142', $result['output']);
        self::assertDatabaseCount('member_activation_verifiers', 0);
    }

    public function test_apply_requires_confirmation_and_the_reviewed_file_hash(): void
    {
        $this->member('IMPORT-B');
        $this->csv([['IMPORT-B', '6/14/1985', '305-555-0142']]);

        self::assertSame(1, $this->run_(['--apply' => true])['exit']);
        self::assertSame(1, $this->run_(['--apply' => true, '--confirm' => 'IMPORT_MEMBER_ACTIVATION_VERIFIERS', '--source-sha256' => str_repeat('0', 64)])['exit']);
        self::assertDatabaseCount('member_activation_verifiers', 0);
    }

    public function test_apply_stores_only_digests_that_then_activate_the_member(): void
    {
        $member = $this->member('IMPORT-C');
        $established = $this->member('IMPORT-ACTIVE');
        $established->forceFill(['account_status' => AccountStatus::Active, 'bootstrap_onboarding_eligible' => false])->save();
        $admin = $this->member('IMPORT-ADMIN');
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->member('IMPORT-BADDATE');
        $this->member('IMPORT-NOPHONE');
        $this->csv([
            ['IMPORT-C', '6/14/1985', '305-555-0142'],
            ['IMPORT-ACTIVE', '1980-01-01', '3055550150'],
            ['IMPORT-ADMIN', '1980-01-01', '3055550151'],
            ['IMPORT-BADDATE', '14/06/1985', '3055550152'],
            ['IMPORT-NOPHONE', '1980-01-01', ''],
        ]);

        $result = $this->apply();

        self::assertSame(0, $result['exit']);
        self::assertSame(['stored' => 1, 'unchanged' => 0], $result['json']['applied']);
        self::assertSame('preserved', $result['json']['established_account_integrity']);
        self::assertSame([
            'established_skipped' => 1, 'invalid_date_of_birth' => 1, 'missing_phone' => 1, 'privileged_skipped' => 1, 'ready' => 1,
        ], $result['json']['statuses']);
        $verifier = MemberActivationVerifier::query()->sole();
        self::assertSame($member->employee_profile_id, $verifier->employee_profile_id);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $verifier->dob_digest);
        self::assertStringNotContainsString('1985', json_encode($verifier->getAttributes(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('3055550142', json_encode($verifier->getAttributes(), JSON_THROW_ON_ERROR));

        $activated = app(MemberSelfServiceActivation::class)->claim(
            'IMPORT-C', CarbonImmutable::create(1985, 6, 14), '3055550142', Hash::make('New-Private-Password!7824'), CarbonImmutable::now(),
        );
        self::assertSame($member->id, $activated?->id);
    }

    public function test_reimport_is_idempotent_and_reapproval_clears_a_lockout(): void
    {
        $member = $this->member('IMPORT-D');
        $this->csv([['IMPORT-D', '1985-06-14', '3055550142']]);
        $this->apply();

        self::assertSame(['stored' => 0, 'unchanged' => 1], $this->apply()['json']['applied']);

        MemberActivationVerifier::query()->update(['failed_attempts' => 10, 'locked_at' => now()]);
        self::assertSame(['stored' => 1, 'unchanged' => 0], $this->apply()['json']['applied']);
        $verifier = MemberActivationVerifier::query()->sole();
        self::assertNull($verifier->locked_at);
        self::assertSame(0, $verifier->failed_attempts);
        self::assertSame($member->employee_profile_id, $verifier->employee_profile_id);
    }

    public function test_duplicate_ids_and_malformed_files_store_nothing(): void
    {
        $this->member('IMPORT-E');
        $this->csv([['IMPORT-E', '1985-06-14', '3055550142'], ['IMPORT-E', '1985-06-15', '3055550142']]);
        self::assertSame(['duplicate_roster_employee_id' => 2], $this->apply()['json']['statuses']);
        self::assertDatabaseCount('member_activation_verifiers', 0);

        file_put_contents($this->path, "Employee ID,Email Address\nIMPORT-E,e@miamibeachfl.gov\n");
        self::assertSame(1, $this->run_([])['exit']);
        self::assertDatabaseCount('member_activation_verifiers', 0);
    }

    /** @param list<array{string,string,string}> $rows */
    private function csv(array $rows): void
    {
        $lines = ["\xEF\xBB\xBFName,Employee ID,Date of Birth,Phone"];
        foreach ($rows as [$id, $dob, $phone]) {
            $lines[] = "\"Member {$id}\",{$id},{$dob},\"{$phone}\"";
        }
        file_put_contents($this->path, implode("\n", $lines)."\n");
    }

    /** @return array{exit:int,output:string,json:array<string,mixed>} */
    private function apply(): array
    {
        return $this->run_([
            '--apply' => true,
            '--confirm' => 'IMPORT_MEMBER_ACTIVATION_VERIFIERS',
            '--source-sha256' => hash_file('sha256', $this->path),
            '--format' => 'json',
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{exit:int,output:string,json:array<string,mixed>}
     */
    private function run_(array $options): array
    {
        $exit = Artisan::call('identity:import-member-activation-verifiers', ['file' => $this->path] + $options);
        $output = Artisan::output();
        $json = json_decode(trim($output), true);

        return ['exit' => $exit, 'output' => $output, 'json' => is_array($json) ? $json : []];
    }

    private function member(string $employeeId): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Import Member '.$employeeId,
            'roster_status' => 'active',
            'password' => Hash::make('legacy-password'),
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employeeId,
            'email' => 'pending-'.strtolower($employeeId).'@canonical.mbfdhub.invalid',
            'account_status' => AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => true,
            'bootstrap_onboarding_eligible_at' => now(),
            'security_version' => 1,
        ])->fresh('employeeProfile');
    }
}
