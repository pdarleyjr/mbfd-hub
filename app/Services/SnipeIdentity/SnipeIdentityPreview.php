<?php

declare(strict_types=1);

namespace App\Services\SnipeIdentity;

final class SnipeIdentityPreview
{
    /**
     * @param  list<array{id: int, employee_id: string, name: string, rank: ?string}>  $employees
     * @param  list<array{id: int, employee_id: ?string, name: string, email: string}>  $users
     * @param  list<array{id: int, employee_num: ?string, username: ?string, email: ?string, name: ?string}>  $snipeUsers
     * @return array<string, mixed>
     */
    public function build(array $employees, array $users, array $snipeUsers): array
    {
        usort($employees, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($users, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($snipeUsers, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        $rows = array_map(fn (array $employee): array => $this->row($employee, $users, $snipeUsers), $employees);

        return [
            'schema_version' => 1,
            'read_only' => true,
            'deterministic' => true,
            'controls' => [
                'snipe_writes_possible' => false,
                'name_auto_match' => false,
                'email_auto_match' => false,
                'apply_mode_available' => false,
            ],
            'required_future_mapping_contract' => [
                'owner' => 'C01_or_external_identity_architecture',
                'fields' => ['system', 'external_numeric_id', 'employee_num_at_capture', 'captured_at', 'approved_by'],
                'constraint' => 'unique(system, external_numeric_id)',
                'rule' => 'Never delete or recreate a Snipe user to reconcile an identity.',
            ],
            'summary' => [
                'employees' => count($employees),
                'snipe_users_read' => count($snipeUsers),
                'classifications' => $this->classificationCounts($rows),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{id: int, employee_id: string, name: string, rank: ?string}  $employee
     * @param  list<array{id: int, employee_id: ?string, name: string, email: string}>  $users
     * @param  list<array{id: int, employee_num: ?string, username: ?string, email: ?string, name: ?string}>  $snipeUsers
     * @return array<string, mixed>
     */
    private function row(array $employee, array $users, array $snipeUsers): array
    {
        $linkedUsers = array_values(array_filter($users, static fn (array $user): bool => $user['employee_id'] === $employee['employee_id']));
        $exact = array_values(array_filter($snipeUsers, static fn (array $user): bool => $user['employee_num'] === $employee['employee_id']));
        $nameOnly = array_values(array_filter($snipeUsers, fn (array $user): bool => $this->equals($user['name'], $employee['name'])));
        $emails = array_values(array_filter(array_column($linkedUsers, 'email'), static fn (string $email): bool => $email !== ''));
        $emailOnly = array_values(array_filter($snipeUsers, fn (array $user): bool => $user['email'] !== null && in_array($user['email'], $emails, true)));

        $classification = 'NO_SNIPE_MATCH';
        $proposedAction = 'OWNER_REVIEW_REQUIRED';
        $currentSnipeId = null;
        if (count($exact) === 1) {
            $classification = 'EXACT_EMPLOYEE_NUM';
            $proposedAction = 'PRESERVE_EXISTING_SNIPE_ID_AND_CAPTURE_MAPPING_FOR_OWNER_APPROVAL';
            $currentSnipeId = $exact[0]['id'];
        } elseif (count($exact) > 1) {
            $classification = 'MULTIPLE_SNIPE_USERS';
        } elseif (count($nameOnly) > 0 && count($emailOnly) === 0) {
            $classification = 'NAME_ONLY_REVIEW';
        } elseif (count($emailOnly) > 0 && count($nameOnly) === 0) {
            $classification = 'EMAIL_ONLY_REVIEW';
        } elseif (count($nameOnly) > 0 || count($emailOnly) > 0) {
            $classification = 'OWNER_REVIEW_REQUIRED';
        }

        return [
            'employee_number' => $employee['employee_id'],
            'employee_db_id' => $employee['id'],
            'name' => $employee['name'],
            'current_user_ids' => array_column($linkedUsers, 'id'),
            'current_users' => array_map(static fn (array $user): array => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
            ], $linkedUsers),
            'current_snipe_numeric_id' => $currentSnipeId,
            'snipe_username' => count($exact) === 1 ? $exact[0]['username'] : null,
            'snipe_employee_num' => count($exact) === 1 ? $exact[0]['employee_num'] : null,
            'local_mapping_status' => 'LOCAL_MAPPING_MISSING',
            'classification' => $classification,
            'proposed_action' => $proposedAction,
            'candidate_snipe_numeric_ids' => array_column($exact, 'id'),
            'name_only_candidate_ids' => array_column($nameOnly, 'id'),
            'email_only_candidate_ids' => array_column($emailOnly, 'id'),
        ];
    }

    private function equals(?string $left, ?string $right): bool
    {
        return $left !== null && $right !== null && mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function classificationCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $classification = $row['classification'];
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }
}
