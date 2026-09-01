<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CanonicalCredentialMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_apply_copy_only_an_explicitly_approved_compatible_legacy_hash(): void
    {
        [$user, $employee] = $this->identityPair('canonical-password', 'legacy-password');
        $issuedAt = CarbonImmutable::parse('2026-08-31T12:00:00Z');
        $oldSession = app(SessionRegistry::class)->register(
            $user,
            'pre-migration-session',
            SessionContextClass::UnmanagedBrowser,
            $issuedAt,
            $issuedAt->addHour(),
            $issuedAt->addDay(),
        );
        $ledger = $this->ledger($user, $employee, 'COPY_COMPATIBLE_LEGACY_HASH');

        try {
            $preview = $this->preview($ledger);
            $userRow = $this->userRow($preview, $user->id);

            $this->assertSame('EXACT_EMPLOYEE_ID_MATCH', $userRow['classification']);
            $this->assertSame('LINK_AND_COPY_COMPATIBLE_HASH', $userRow['proposed_action']);
            $this->assertSame('APPROVED_COMPATIBLE_LEGACY_HASH_COPY', $userRow['credential_transition']['state']);
            $this->assertStringNotContainsString($user->getRawOriginal('password'), json_encode($preview, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($employee->getRawOriginal('password'), json_encode($preview, JSON_THROW_ON_ERROR));

            $status = Artisan::call('identity:reconcile-apply', [
                '--approved-ledger' => $ledger,
                '--snapshot-token' => $preview['snapshot_token'],
                '--confirm' => 'APPLY_OWNER_APPROVED_LINKS',
            ]);
            $applyOutput = Artisan::output();
            $result = json_decode($applyOutput, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(Command::SUCCESS, $status);
            $this->assertSame(1, $result['links_applied']);
            $this->assertSame(1, $result['credential_hashes_copied']);
            $user->refresh();
            $this->assertSame($employee->id, $user->employee_profile_id);
            $this->assertSame($employee->getRawOriginal('password'), $user->getRawOriginal('password'));
            $this->assertSame(2, $user->security_version);
            $this->assertNotNull($user->password_changed_at);
            $this->assertNotNull($oldSession->fresh()->revoked_at);
            $this->assertStringNotContainsString($employee->getRawOriginal('password'), $applyOutput);
        } finally {
            @unlink($ledger);
        }
    }

    public function test_different_hashes_without_explicit_credential_approval_remain_a_conflict_and_are_not_applied(): void
    {
        [$user, $employee] = $this->identityPair('canonical-password', 'legacy-password');
        $canonicalHash = $user->getRawOriginal('password');
        $ledger = $this->ledger($user, $employee, null);

        try {
            $preview = $this->preview($ledger);
            $userRow = $this->userRow($preview, $user->id);
            $this->assertSame('CREDENTIAL_CONFLICT', $userRow['classification']);
            $this->assertSame('DIFFERENT_HASH', $userRow['blocked_reason']);

            $status = Artisan::call('identity:reconcile-apply', [
                '--approved-ledger' => $ledger,
                '--snapshot-token' => $preview['snapshot_token'],
                '--confirm' => 'APPLY_OWNER_APPROVED_LINKS',
            ]);

            $this->assertSame(Command::FAILURE, $status);
            $this->assertNull($user->fresh()->employee_profile_id);
            $this->assertSame($canonicalHash, $user->fresh()->getRawOriginal('password'));
        } finally {
            @unlink($ledger);
        }
    }

    public function test_explicit_canonical_credential_preservation_links_without_overwriting_or_revoking(): void
    {
        [$user, $employee] = $this->identityPair('canonical-password', 'different-legacy-password');
        $canonicalHash = $user->getRawOriginal('password');
        $ledger = $this->ledger($user, $employee, 'PRESERVE_CANONICAL_HASH');

        try {
            $preview = $this->preview($ledger);
            $userRow = $this->userRow($preview, $user->id);
            $this->assertSame('LINK', $userRow['proposed_action']);
            $this->assertSame('APPROVED_CANONICAL_HASH_PRESERVED', $userRow['credential_transition']['state']);

            $status = Artisan::call('identity:reconcile-apply', [
                '--approved-ledger' => $ledger,
                '--snapshot-token' => $preview['snapshot_token'],
                '--confirm' => 'APPLY_OWNER_APPROVED_LINKS',
            ]);
            $applyOutput = Artisan::output();
            $result = json_decode($applyOutput, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(Command::SUCCESS, $status);
            $this->assertSame(1, $result['links_applied']);
            $this->assertSame(0, $result['credential_hashes_copied']);
            $user->refresh();
            $this->assertSame($employee->id, $user->employee_profile_id);
            $this->assertSame($canonicalHash, $user->getRawOriginal('password'));
            $this->assertSame(1, $user->security_version);
        } finally {
            @unlink($ledger);
        }
    }

    public function test_apply_rejects_a_stale_or_unconfirmed_preview_without_mutation(): void
    {
        [$user, $employee] = $this->identityPair('canonical-password', 'legacy-password');
        $ledger = $this->ledger($user, $employee, 'COPY_COMPATIBLE_LEGACY_HASH');

        try {
            $status = Artisan::call('identity:reconcile-apply', [
                '--approved-ledger' => $ledger,
                '--snapshot-token' => str_repeat('0', 64),
                '--confirm' => 'APPLY_OWNER_APPROVED_LINKS',
            ]);

            $this->assertSame(Command::FAILURE, $status);
            $this->assertNull($user->fresh()->employee_profile_id);
        } finally {
            @unlink($ledger);
        }
    }

    /** @return array{User, Employee} */
    private function identityPair(string $canonicalPassword, string $employeePassword): array
    {
        $employee = Employee::query()->create([
            'employee_id' => '10010',
            'name' => 'Credential Migration Employee',
            'rank' => 'Firefighter',
            'password' => $employeePassword,
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'employee_id' => null,
            'employee_profile_id' => null,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make($canonicalPassword),
        ]);

        return [$user, $employee];
    }

    private function ledger(User $user, Employee $employee, ?string $credentialAction): string
    {
        $path = tempnam(sys_get_temp_dir(), 'd01-owner-ledger-');
        self::assertNotFalse($path);
        $entry = [
            'user_id' => $user->id,
            'employee_id' => $employee->employee_id,
            'decision' => 'LINK',
            'approved_by' => 'Synthetic Identity Owner',
            'approved_at' => '2026-08-31T12:00:00-04:00',
            'approval_reference' => 'D01-TEST-APPROVAL',
            'notes' => 'Synthetic test approval only',
        ];
        if ($credentialAction !== null) {
            $entry['credential_action'] = $credentialAction;
        }
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'entries' => [$entry],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, mixed> */
    private function preview(string $ledger): array
    {
        $status = Artisan::call('identity:reconcile-preview', [
            '--format' => 'json',
            '--approved-ledger' => $ledger,
        ]);
        $output = Artisan::output();
        $this->assertSame(Command::SUCCESS, $status, $output);
        $this->assertJson($output, $output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function userRow(array $preview, int $userId): array
    {
        foreach ($preview['rows'] as $row) {
            if ($row['entity_type'] === 'USER' && $row['entity_id'] === $userId) {
                return $row;
            }
        }

        $this->fail("Preview did not contain user {$userId}.");
    }
}
