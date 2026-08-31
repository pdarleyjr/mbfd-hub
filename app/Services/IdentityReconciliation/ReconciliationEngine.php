<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use App\Data\IdentityReconciliation\EmployeeIdentity;
use App\Data\IdentityReconciliation\OwnerLedgerEntry;
use App\Data\IdentityReconciliation\UserIdentity;
use JsonException;

final class ReconciliationEngine
{
    public function __construct(private readonly CredentialInspector $credentials) {}

    /**
     * @param  list<UserIdentity>  $users
     * @param  list<EmployeeIdentity>  $employees
     * @param  list<OwnerLedgerEntry>  $ledger
     * @return array<string, mixed>
     */
    public function reconcile(array $users, array $employees, array $ledger = []): array
    {
        usort($users, static fn (UserIdentity $left, UserIdentity $right): int => $left->id <=> $right->id);
        usort($employees, static fn (EmployeeIdentity $left, EmployeeIdentity $right): int => $left->id <=> $right->id);
        $this->assertLedgerReferencesExist($users, $employees, $ledger);

        $employeesByIdentifier = $this->groupEmployees($employees);
        $ledgerByUser = [];
        $ledgerByEmployee = [];
        foreach ($ledger as $entry) {
            if ($entry->userId !== null) {
                $ledgerByUser[$entry->userId] = $entry;
            }
            if ($entry->employeeId !== null) {
                $ledgerByEmployee[$entry->employeeId] = $entry;
            }
        }

        $targetByUser = [];
        foreach ($users as $user) {
            $entry = $ledgerByUser[$user->id] ?? null;
            $targetByUser[$user->id] = $entry?->decision === 'LINK'
                ? $entry->employeeId
                : $user->legacyEmployeeId;
        }
        $usersByTarget = $this->groupUsersByTarget($users, $targetByUser);
        $duplicateSnipeIds = $this->duplicateSnipeNumericIds($users, $employees);

        $rows = [];
        $userRowsById = [];
        foreach ($users as $user) {
            $row = $this->userRow(
                $user,
                $employeesByIdentifier,
                $usersByTarget,
                $targetByUser,
                $ledgerByUser[$user->id] ?? null,
                $duplicateSnipeIds,
            );
            $rows[] = $row;
            $userRowsById[$user->id] = $row;
        }

        foreach ($employees as $employee) {
            $rows[] = $this->employeeRow(
                $employee,
                $employeesByIdentifier,
                $usersByTarget,
                $userRowsById,
                $ledgerByEmployee[$employee->employeeId] ?? null,
                $duplicateSnipeIds,
            );
        }

        $report = [
            'schema_version' => 1,
            'read_only' => true,
            'deterministic' => true,
            'gate_status' => $this->hasBlockers($rows) ? 'BLOCKED' : 'READY_FOR_OWNER_REVIEW',
            'controls' => [
                'production_mutations_possible' => false,
                'name_auto_match' => false,
                'external_network_calls' => false,
                'database_access' => 'SELECT_ONLY_POSTGRES_READ_ONLY_TRANSACTION',
                'password_hashes_exported' => false,
                'owner_approvals_invented' => false,
            ],
            'artifact_handling' => [
                'contains_personnel_data' => true,
                'output_file_mode' => '0600_WHERE_SUPPORTED',
                'retention' => 'DELETE_WHEN_OWNER_REVIEW_IS_COMPLETE',
                'commit_production_preview' => false,
            ],
            'external_systems' => [
                'snipe' => [
                    'status' => 'NOT_PERSISTED_IN_BASELINE',
                    'behavior' => 'LOCAL_EVIDENCE_ONLY_NO_API_CALL',
                ],
                'bid' => [
                    'status' => 'EMPLOYEE_ID_REFERENCE_ONLY',
                    'behavior' => 'NO_EXTERNAL_CALL',
                ],
                'screentinker' => [
                    'status' => 'USER_EMAIL_REFERENCE_ONLY',
                    'behavior' => 'NO_EXTERNAL_CALL',
                ],
            ],
            'summary' => $this->summary($rows, count($users), count($employees), $employeesByIdentifier),
            'rows' => $rows,
        ];

        try {
            $canonical = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Could not normalize the identity reconciliation report.', 0, $exception);
        }
        $report['snapshot_token'] = $this->credentials->fingerprintPayload($canonical);

        return $report;
    }

    /**
     * @param  array<string, list<EmployeeIdentity>>  $employeesByIdentifier
     * @param  array<string, list<UserIdentity>>  $usersByTarget
     * @param  array<int, string|null>  $targetByUser
     * @param  array<string, true>  $duplicateSnipeIds
     * @return array<string, mixed>
     */
    private function userRow(
        UserIdentity $user,
        array $employeesByIdentifier,
        array $usersByTarget,
        array $targetByUser,
        ?OwnerLedgerEntry $ledger,
        array $duplicateSnipeIds,
    ): array {
        $base = $this->baseUserRow($user, $ledger);

        if ($ledger?->decision === 'QUARANTINE') {
            return array_merge($base, [
                'classification' => 'TEST_OR_SERVICE_ACCOUNT',
                'proposed_action' => 'QUARANTINE',
                'blocked_reason' => null,
                'evidence' => 'OWNER_APPROVED_LEDGER',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        if ($ledger === null && $this->isFrocTestIdentity($user)) {
            return array_merge($base, [
                'classification' => 'TEST_OR_SERVICE_ACCOUNT',
                'proposed_action' => 'QUARANTINE',
                'blocked_reason' => 'OWNER_CLASSIFICATION_REQUIRED',
                'evidence' => 'FROC_TEST_IDENTIFIER',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        $target = $targetByUser[$user->id];
        if ($ledger?->decision === 'LINK'
            && $user->legacyEmployeeId !== null
            && $user->legacyEmployeeId !== ''
            && $user->legacyEmployeeId !== $ledger->employeeId) {
            return array_merge($base, [
                'classification' => 'CONFLICTING_EMPLOYEE_ID',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'LEDGER_DISAGREES_WITH_LEGACY_EMPLOYEE_ID',
                'evidence' => 'OWNER_LEDGER_AND_LEGACY_FIELD',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        if ($target === null || $target === '') {
            return array_merge($base, [
                'classification' => 'USER_MISSING_EMPLOYEE_ID',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'OWNER_MAPPING_REQUIRED',
                'evidence' => 'LEGACY_EMPLOYEE_ID_MISSING',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        if (trim($target) !== $target) {
            return array_merge($base, [
                'classification' => 'INVALID_DATA',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'EMPLOYEE_ID_HAS_SURROUNDING_WHITESPACE',
                'evidence' => $ledger === null ? 'LEGACY_EMPLOYEE_ID' : 'OWNER_APPROVED_LEDGER',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        $candidateEmployees = $employeesByIdentifier[$target] ?? [];
        if ($candidateEmployees === []) {
            return array_merge($base, [
                'classification' => 'NO_MATCH',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'EMPLOYEE_ID_NOT_FOUND',
                'evidence' => $ledger === null ? 'LEGACY_EMPLOYEE_ID' : 'OWNER_APPROVED_LEDGER',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        if (count($candidateEmployees) > 1) {
            return array_merge($base, [
                'classification' => 'MULTIPLE_MATCHES',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'DUPLICATE_EMPLOYEE_ID',
                'evidence' => 'EXACT_EMPLOYEE_ID_COLLISION',
                'credential_comparison' => 'NOT_COMPARABLE',
                'employee' => $this->safeEmployee($candidateEmployees[0]),
                'candidate_employee_db_ids' => array_map(static fn (EmployeeIdentity $employee): int => $employee->id, $candidateEmployees),
            ]);
        }

        if (count($usersByTarget[$target] ?? []) > 1) {
            return array_merge($base, [
                'classification' => 'MULTIPLE_MATCHES',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'MULTIPLE_USERS_FOR_EMPLOYEE_ID',
                'evidence' => 'EXACT_EMPLOYEE_ID_COLLISION',
                'credential_comparison' => 'NOT_COMPARABLE',
                'employee' => $this->safeEmployee($candidateEmployees[0]),
                'candidate_user_ids' => array_map(static fn (UserIdentity $candidate): int => $candidate->id, $usersByTarget[$target]),
            ]);
        }

        $employee = $candidateEmployees[0];
        $base['employee'] = $this->safeEmployee($employee);
        $externalConflict = $this->externalConflict($target, array_merge($user->externalMappings, $employee->externalMappings), $duplicateSnipeIds);
        if ($externalConflict !== null) {
            return array_merge($base, [
                'classification' => 'EXTERNAL_MAPPING_CONFLICT',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => $externalConflict,
                'evidence' => 'LOCAL_EXTERNAL_MAPPING_EVIDENCE',
                'credential_comparison' => $this->credentials->compare($user->passwordHash, $employee->passwordHash),
            ]);
        }

        $comparison = $this->credentials->compare($user->passwordHash, $employee->passwordHash);
        if ($comparison !== 'SAME_HASH') {
            return array_merge($base, [
                'classification' => 'CREDENTIAL_CONFLICT',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => $comparison,
                'evidence' => $ledger === null ? 'EXACT_LEGACY_EMPLOYEE_ID' : 'OWNER_APPROVED_LEDGER',
                'credential_comparison' => $comparison,
            ]);
        }

        return array_merge($base, [
            'classification' => 'EXACT_EMPLOYEE_ID_MATCH',
            'proposed_action' => 'LINK',
            'blocked_reason' => null,
            'evidence' => $ledger === null ? 'EXACT_LEGACY_EMPLOYEE_ID' : 'OWNER_APPROVED_LEDGER',
            'credential_comparison' => $comparison,
        ]);
    }

    /**
     * @param  array<string, list<EmployeeIdentity>>  $employeesByIdentifier
     * @param  array<string, list<UserIdentity>>  $usersByTarget
     * @param  array<int, array<string, mixed>>  $userRowsById
     * @param  array<string, true>  $duplicateSnipeIds
     * @return array<string, mixed>
     */
    private function employeeRow(
        EmployeeIdentity $employee,
        array $employeesByIdentifier,
        array $usersByTarget,
        array $userRowsById,
        ?OwnerLedgerEntry $ledger,
        array $duplicateSnipeIds,
    ): array {
        $base = [
            'row_key' => sprintf('EMPLOYEE:%020d', $employee->id),
            'entity_type' => 'EMPLOYEE',
            'entity_id' => $employee->id,
            'user' => null,
            'employee' => $this->safeEmployee($employee),
            'owner_decision' => $ledger?->safeApproval(),
            'external_mappings' => $employee->externalMappings,
            'preservation' => [
                'employee_primary_key' => $employee->id,
                'employee_foreign_keys' => 'PRESERVE',
                'operational_history' => 'PRESERVE',
            ],
        ];

        if ($employee->employeeId === '' || trim($employee->employeeId) !== $employee->employeeId) {
            return array_merge($base, [
                'classification' => 'INVALID_DATA',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => $employee->employeeId === '' ? 'EMPLOYEE_ID_BLANK' : 'EMPLOYEE_ID_HAS_SURROUNDING_WHITESPACE',
                'evidence' => 'EMPLOYEE_RECORD',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        if (count($employeesByIdentifier[$employee->employeeId] ?? []) > 1) {
            return array_merge($base, [
                'classification' => 'MULTIPLE_MATCHES',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'DUPLICATE_EMPLOYEE_ID',
                'evidence' => 'EXACT_EMPLOYEE_ID_COLLISION',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        $candidateUsers = $usersByTarget[$employee->employeeId] ?? [];
        if (count($candidateUsers) > 1) {
            return array_merge($base, [
                'classification' => 'MULTIPLE_MATCHES',
                'proposed_action' => 'BLOCKED',
                'blocked_reason' => 'MULTIPLE_USERS_FOR_EMPLOYEE_ID',
                'evidence' => 'EXACT_EMPLOYEE_ID_COLLISION',
                'credential_comparison' => 'NOT_COMPARABLE',
                'candidate_user_ids' => array_map(static fn (UserIdentity $user): int => $user->id, $candidateUsers),
            ]);
        }

        if ($candidateUsers === []) {
            $approvedCreate = $ledger?->decision === 'CREATE_USER';

            return array_merge($base, [
                'classification' => 'EMPLOYEE_WITHOUT_USER',
                'proposed_action' => 'CREATE_USER',
                'blocked_reason' => $approvedCreate ? null : 'OWNER_APPROVAL_REQUIRED',
                'evidence' => $approvedCreate ? 'OWNER_APPROVED_LEDGER' : 'NO_USER_EMPLOYEE_ID_REFERENCE',
                'credential_comparison' => 'NOT_COMPARABLE',
            ]);
        }

        $user = $candidateUsers[0];
        $userRow = $userRowsById[$user->id];
        $base['user'] = $userRow['user'];
        $base['preservation'] = $userRow['preservation'];
        $base['external_mappings'] = array_merge($user->externalMappings, $employee->externalMappings);

        return array_merge($base, [
            'classification' => $userRow['classification'],
            'proposed_action' => $userRow['proposed_action'],
            'blocked_reason' => $userRow['blocked_reason'],
            'evidence' => $userRow['evidence'],
            'credential_comparison' => $userRow['credential_comparison'],
        ]);
    }

    /** @return array<string, mixed> */
    private function baseUserRow(UserIdentity $user, ?OwnerLedgerEntry $ledger): array
    {
        $roles = $user->roles;
        $directPermissions = $user->directPermissions;
        $effectivePermissions = $user->effectivePermissions;
        $workgroups = $user->workgroups;
        sort($roles, SORT_STRING);
        sort($directPermissions, SORT_STRING);
        sort($effectivePermissions, SORT_STRING);
        usort($workgroups, static fn (array $left, array $right): int => [$left['id'], $left['role']] <=> [$right['id'], $right['role']]);

        return [
            'row_key' => sprintf('USER:%020d', $user->id),
            'entity_type' => 'USER',
            'entity_id' => $user->id,
            'user' => [
                'id' => $user->id,
                'legacy_employee_id' => $user->legacyEmployeeId,
                'employee_profile_id' => $user->employeeProfileId,
                'name' => $user->name,
                'email' => $user->email,
                'login_identifier' => $user->email,
                'login_identifier_type' => 'EMAIL',
                'rank' => $user->rank,
                'account_status' => $user->accountStatus,
                'security_version' => $user->securityVersion,
                'must_change_password' => $user->mustChangePassword,
                'credential' => $this->credentials->inspect($user->passwordHash),
            ],
            'employee' => null,
            'owner_decision' => $ledger?->safeApproval(),
            'external_mappings' => $user->externalMappings,
            'preservation' => [
                'user_primary_key' => $user->id,
                'roles' => $roles,
                'direct_permissions' => $directPermissions,
                'effective_permissions' => $effectivePermissions,
                'workgroups' => $workgroups,
                'training_access' => in_array('training_admin', $roles, true)
                    || in_array('training_viewer', $roles, true)
                    || in_array('training.access', $effectivePermissions, true),
                'admin_access' => array_intersect($roles, ['super_admin', 'admin', 'logistics_admin', 'training_admin', 'training_viewer']) !== [],
                'super_admin' => in_array('super_admin', $roles, true),
                'notification_relationships' => $user->notificationRelationships,
                'all_user_foreign_keys' => 'PRESERVE',
                'all_employee_foreign_keys' => 'PRESERVE',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function safeEmployee(EmployeeIdentity $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_id' => $employee->employeeId,
            'name' => $employee->name,
            'rank' => $employee->rank,
            'active_status' => null,
            'must_change_password' => $employee->mustChangePassword,
            'credential' => $this->credentials->inspect($employee->passwordHash),
        ];
    }

    /** @param list<EmployeeIdentity> $employees
     * @return array<string, list<EmployeeIdentity>>
     */
    private function groupEmployees(array $employees): array
    {
        $grouped = [];
        foreach ($employees as $employee) {
            $grouped[$employee->employeeId][] = $employee;
        }

        return $grouped;
    }

    /**
     * @param  list<UserIdentity>  $users
     * @param  array<int, string|null>  $targetByUser
     * @return array<string, list<UserIdentity>>
     */
    private function groupUsersByTarget(array $users, array $targetByUser): array
    {
        $grouped = [];
        foreach ($users as $user) {
            $target = $targetByUser[$user->id];
            if ($target !== null && $target !== '' && trim($target) === $target) {
                $grouped[$target][] = $user;
            }
        }

        return $grouped;
    }

    /** @param list<UserIdentity> $users
     * @param  list<EmployeeIdentity>  $employees
     * @return array<string, true>
     */
    private function duplicateSnipeNumericIds(array $users, array $employees): array
    {
        $counts = [];
        foreach ([...$users, ...$employees] as $identity) {
            foreach ($identity->externalMappings as $mapping) {
                if (($mapping['system'] ?? null) !== 'snipe' || ! isset($mapping['numeric_id'])) {
                    continue;
                }
                $numericId = (string) $mapping['numeric_id'];
                if ($numericId !== '') {
                    $counts[$numericId] = ($counts[$numericId] ?? 0) + 1;
                }
            }
        }

        return array_fill_keys(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)), true);
    }

    /**
     * @param  list<array<string, int|string|null>>  $mappings
     * @param  array<string, true>  $duplicateSnipeIds
     */
    private function externalConflict(string $employeeId, array $mappings, array $duplicateSnipeIds): ?string
    {
        foreach ($mappings as $mapping) {
            if (($mapping['system'] ?? null) !== 'snipe') {
                continue;
            }
            $employeeNumber = $mapping['employee_num'] ?? null;
            if (is_string($employeeNumber) && $employeeNumber !== '' && $employeeNumber !== $employeeId) {
                return 'SNIPE_EMPLOYEE_NUMBER_DISAGREEMENT';
            }
            $numericId = $mapping['numeric_id'] ?? null;
            if ($numericId !== null && isset($duplicateSnipeIds[(string) $numericId])) {
                return 'SNIPE_NUMERIC_ID_DUPLICATE';
            }
        }

        return null;
    }

    private function isFrocTestIdentity(UserIdentity $user): bool
    {
        $employeeId = strtoupper($user->legacyEmployeeId ?? '');

        return str_starts_with($employeeId, 'FROC-TEST-');
    }

    /**
     * @param  list<UserIdentity>  $users
     * @param  list<EmployeeIdentity>  $employees
     * @param  list<OwnerLedgerEntry>  $ledger
     */
    private function assertLedgerReferencesExist(array $users, array $employees, array $ledger): void
    {
        $userIds = array_fill_keys(array_map(static fn (UserIdentity $user): int => $user->id, $users), true);
        $employeeIds = array_fill_keys(array_map(static fn (EmployeeIdentity $employee): string => $employee->employeeId, $employees), true);

        foreach ($ledger as $entry) {
            if ($entry->userId !== null && ! isset($userIds[$entry->userId])) {
                throw new InvalidOwnerLedger("Owner ledger references unknown user_id {$entry->userId}.");
            }
            if ($entry->employeeId !== null && ! isset($employeeIds[$entry->employeeId])) {
                throw new InvalidOwnerLedger("Owner ledger references unknown employee_id {$entry->employeeId}.");
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function hasBlockers(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['blocked_reason'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<EmployeeIdentity>>  $employeesByIdentifier
     * @return array<string, mixed>
     */
    private function summary(array $rows, int $userCount, int $employeeCount, array $employeesByIdentifier): array
    {
        $userRows = array_values(array_filter($rows, static fn (array $row): bool => $row['entity_type'] === 'USER'));
        $employeeRows = array_values(array_filter($rows, static fn (array $row): bool => $row['entity_type'] === 'EMPLOYEE'));
        $classifications = [];
        foreach ($rows as $row) {
            $classifications[$row['classification']] = ($classifications[$row['classification']] ?? 0) + 1;
        }
        ksort($classifications, SORT_STRING);

        return [
            'total_users' => $userCount,
            'total_employees' => $employeeCount,
            'total_rows' => count($rows),
            'exact_matches' => count(array_filter($userRows, static fn (array $row): bool => $row['employee'] !== null
                && in_array($row['classification'], [
                    'EXACT_EMPLOYEE_ID_MATCH',
                    'CREDENTIAL_CONFLICT',
                    'EXTERNAL_MAPPING_CONFLICT',
                ], true))),
            'link_ready_matches' => $this->countRows($userRows, 'EXACT_EMPLOYEE_ID_MATCH'),
            'unmatched_users' => count(array_filter($userRows, static fn (array $row): bool => in_array($row['classification'], [
                'USER_MISSING_EMPLOYEE_ID',
                'NO_MATCH',
                'TEST_OR_SERVICE_ACCOUNT',
                'INVALID_DATA',
            ], true))),
            'unmatched_employees' => $this->countRows($employeeRows, 'EMPLOYEE_WITHOUT_USER'),
            'collisions' => $this->countRows($rows, 'MULTIPLE_MATCHES'),
            'invalid_employee_ids' => $this->countRows($rows, 'INVALID_DATA'),
            'duplicate_ids' => count(array_filter($employeesByIdentifier, static fn (array $group): bool => count($group) > 1)),
            'test_service_accounts' => $this->countRows($userRows, 'TEST_OR_SERVICE_ACCOUNT'),
            'owner_review_required_rows' => count(array_filter($rows, static fn (array $row): bool => in_array($row['blocked_reason'], [
                'OWNER_MAPPING_REQUIRED',
                'OWNER_APPROVAL_REQUIRED',
                'OWNER_CLASSIFICATION_REQUIRED',
                'EMPLOYEE_ID_NOT_FOUND',
            ], true))),
            'external_conflicts' => $this->countRows($userRows, 'EXTERNAL_MAPPING_CONFLICT'),
            'credential_conflicts' => $this->countRows($userRows, 'CREDENTIAL_CONFLICT'),
            'classifications' => $classifications,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function countRows(array $rows, string $classification): int
    {
        return count(array_filter($rows, static fn (array $row): bool => $row['classification'] === $classification));
    }
}
