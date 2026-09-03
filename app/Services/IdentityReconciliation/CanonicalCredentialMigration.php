<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use App\Data\IdentityReconciliation\OwnerLedgerEntry;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalUserProvisioner;
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
        private CanonicalUserProvisioner $provisioner,
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
        if ($ledger === [] || array_filter(
            $ledger,
            static fn (OwnerLedgerEntry $entry): bool => ! in_array($entry->decision, ['LINK', 'CREATE_USER'], true),
        ) !== []) {
            throw new RuntimeException('Apply accepts a non-empty owner ledger containing LINK and CREATE_USER decisions only.');
        }

        $report = $this->preview->build($ledger);
        if (! hash_equals((string) $report['snapshot_token'], $snapshotToken)) {
            throw new RuntimeException('The preview snapshot token is stale or belongs to different input.');
        }

        $rowsByUser = [];
        $rowsByEmployee = [];
        foreach ($report['rows'] as $row) {
            if ($row['entity_type'] === 'USER') {
                $rowsByUser[(int) $row['entity_id']] = $row;
            } elseif ($row['entity_type'] === 'EMPLOYEE') {
                $rowsByEmployee[(int) $row['entity_id']] = $row;
            }
        }
        foreach ($ledger as $entry) {
            if ($entry->decision === 'CREATE_USER') {
                $employee = Employee::query()->where('employee_id', $entry->employeeId)->sole();
                $row = $rowsByEmployee[$employee->id] ?? null;
                if ($row === null || $row['blocked_reason'] !== null
                    || ! in_array($row['proposed_action'], ['CREATE_USER', 'LINK', 'LINK_AND_COPY_COMPATIBLE_HASH'], true)) {
                    throw new RuntimeException("Owner-approved Employee {$entry->employeeId} is not create-ready in this snapshot.");
                }

                continue;
            }

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
            $usersCreated = 0;
            $usersActivated = 0;
            $alreadyApplied = 0;
            $at = CarbonImmutable::now();

            foreach ($ledger as $entry) {
                if ($entry->decision === 'CREATE_USER') {
                    /** @var Employee $employee */
                    $employee = Employee::query()
                        ->where('employee_id', $entry->employeeId)
                        ->sole();
                    $outcome = $this->provisioner->create(
                        $employee->id,
                        (string) $entry->credentialProvenance,
                        $at,
                    );
                    $usersCreated += $outcome['created'] ? 1 : 0;
                    $credentialHashesCopied += $outcome['credential_hash_copied'] ? 1 : 0;
                    $usersActivated += $outcome['activated'] ? 1 : 0;
                    $alreadyApplied += $outcome['created'] ? 0 : 1;

                    Log::notice('canonical_identity_user_creation_approved', [
                        'user_id' => $outcome['user']->id,
                        'employee_profile_id' => $employee->id,
                        'approval_reference' => $entry->approvalReference,
                        'created' => $outcome['created'],
                    ]);

                    continue;
                }

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

                $wasUnlinked = $user->employee_profile_id === null;
                $transition = $this->accountSecurity->completeCanonicalLink(
                    $user,
                    $employee->id,
                    $employee->employee_id,
                    $copyHash ? $employeeHash : null,
                    $at,
                );

                if ($transition['changed'] && $wasUnlinked) {
                    $linksApplied++;
                }
                if ($transition['password_changed']) {
                    $credentialHashesCopied++;
                }
                $usersActivated += $transition['activated'] ? 1 : 0;
                $alreadyApplied += $transition['changed'] ? 0 : 1;

                Log::notice('canonical_identity_migration_applied', [
                    'user_id' => $user->id,
                    'employee_profile_id' => $employee->id,
                    'approval_reference' => $entry->approvalReference,
                    'credential_action' => $entry->credentialAction ?? 'HASHES_ALREADY_EQUAL',
                    'credential_hash_copied' => $transition['password_changed'],
                    'account_activated' => $transition['activated'],
                ]);
            }

            return [
                'status' => 'APPLIED_OWNER_APPROVED_RECORDS',
                'owner_approved_records' => count($ledger),
                'links_applied' => $linksApplied,
                'users_created' => $usersCreated,
                'credential_hashes_copied' => $credentialHashesCopied,
                'users_activated' => $usersActivated,
                'already_applied' => $alreadyApplied,
            ];
        }, 3);
    }
}
