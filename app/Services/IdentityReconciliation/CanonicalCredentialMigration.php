<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use App\Data\IdentityReconciliation\OwnerLedgerEntry;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final readonly class CanonicalCredentialMigration
{
    public function __construct(
        private IdentityReconciliationPreview $preview,
        private CredentialInspector $credentials,
        private AccountSecurityService $accountSecurity,
    ) {}

    /**
     * @param  list<OwnerLedgerEntry>  $ledger
     * @return array<string, int|string>
     */
    public function apply(array $ledger, string $snapshotToken, string $confirmation): array
    {
        if ($confirmation !== 'APPLY_OWNER_APPROVED_LINKS') {
            throw new RuntimeException('The exact apply confirmation was not provided.');
        }
        if ($snapshotToken === '' || ! preg_match('/^[a-f0-9]{64}$/', $snapshotToken)) {
            throw new RuntimeException('A valid preview snapshot token is required.');
        }
        if ($ledger === [] || array_filter($ledger, static fn (OwnerLedgerEntry $entry): bool => $entry->decision !== 'LINK') !== []) {
            throw new RuntimeException('Apply accepts a non-empty owner ledger containing LINK decisions only.');
        }

        $report = $this->preview->build($ledger);
        if (! hash_equals((string) $report['snapshot_token'], $snapshotToken)) {
            throw new RuntimeException('The preview snapshot token is stale or belongs to different input.');
        }

        $rowsByUser = [];
        foreach ($report['rows'] as $row) {
            if ($row['entity_type'] === 'USER') {
                $rowsByUser[(int) $row['entity_id']] = $row;
            }
        }
        foreach ($ledger as $entry) {
            $row = $rowsByUser[$entry->userId] ?? null;
            if ($row === null
                || $row['classification'] !== 'EXACT_EMPLOYEE_ID_MATCH'
                || $row['blocked_reason'] !== null
                || ! in_array($row['proposed_action'], ['LINK', 'LINK_AND_COPY_COMPATIBLE_HASH'], true)) {
                throw new RuntimeException("Owner-approved user {$entry->userId} is not apply-ready in this snapshot.");
            }
        }

        return DB::transaction(function () use ($ledger): array {
            $linksApplied = 0;
            $credentialHashesCopied = 0;
            $at = CarbonImmutable::now();

            foreach ($ledger as $entry) {
                /** @var User $user */
                $user = User::query()->lockForUpdate()->findOrFail($entry->userId);
                /** @var Employee $employee */
                $employee = Employee::query()
                    ->where('employee_id', $entry->employeeId)
                    ->lockForUpdate()
                    ->sole();

                if ($user->employee_profile_id !== null && $user->employee_profile_id !== $employee->id) {
                    throw new RuntimeException("User {$user->id} is already linked to a different Employee profile.");
                }
                if ($user->employee_id !== null && $user->employee_id !== $employee->employee_id) {
                    throw new RuntimeException("User {$user->id} has a conflicting legacy Employee ID.");
                }
                if (User::query()
                    ->where('employee_profile_id', $employee->id)
                    ->where('id', '!=', $user->id)
                    ->exists()) {
                    throw new RuntimeException("Employee {$employee->id} is already linked to a different User.");
                }

                $userHash = (string) $user->getRawOriginal('password');
                $employeeHash = (string) $employee->getRawOriginal('password');
                $comparison = $this->credentials->compare($userHash, $employeeHash);
                $copyHash = false;

                if ($entry->credentialAction === 'COPY_COMPATIBLE_LEGACY_HASH') {
                    $legacyCredential = $this->credentials->inspect($employeeHash);
                    if ($legacyCredential['state'] !== 'HASH_PRESENT' || $legacyCredential['algorithm'] !== 'BCRYPT') {
                        throw new RuntimeException("Employee {$employee->id} does not have an apply-compatible legacy credential.");
                    }
                    $copyHash = $comparison !== 'SAME_HASH';
                } elseif ($entry->credentialAction === 'PRESERVE_CANONICAL_HASH') {
                    $canonicalCredential = $this->credentials->inspect($userHash);
                    if ($canonicalCredential['state'] !== 'HASH_PRESENT' || $canonicalCredential['algorithm'] === 'UNSUPPORTED') {
                        throw new RuntimeException("User {$user->id} does not have a supported canonical credential to preserve.");
                    }
                } elseif ($comparison !== 'SAME_HASH') {
                    throw new RuntimeException("User {$user->id} has an unresolved credential conflict.");
                }

                $changes = [
                    'employee_profile_id' => $employee->id,
                    'updated_at' => $at,
                ];
                if ($copyHash) {
                    // Query-builder assignment intentionally bypasses the model's
                    // hashed cast so an approved bcrypt hash is copied byte-for-byte.
                    $changes['password'] = $employeeHash;
                }
                DB::table('users')->where('id', $user->id)->update($changes);

                if ($user->employee_profile_id === null) {
                    $linksApplied++;
                }
                if ($copyHash) {
                    $credentialHashesCopied++;
                    $this->accountSecurity->recordPasswordChange($user->fresh(), $at);
                }

                Log::notice('canonical_identity_migration_applied', [
                    'user_id' => $user->id,
                    'employee_profile_id' => $employee->id,
                    'approval_reference' => $entry->approvalReference,
                    'credential_action' => $entry->credentialAction ?? 'HASHES_ALREADY_EQUAL',
                    'credential_hash_copied' => $copyHash,
                ]);
            }

            return [
                'status' => 'APPLIED_OWNER_APPROVED_RECORDS',
                'owner_approved_records' => count($ledger),
                'links_applied' => $linksApplied,
                'credential_hashes_copied' => $credentialHashesCopied,
            ];
        }, 3);
    }
}
