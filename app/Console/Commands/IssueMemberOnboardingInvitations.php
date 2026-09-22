<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\MemberOnboardingInvitation;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use App\Services\Identity\MemberOnboardingInvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class IssueMemberOnboardingInvitations extends Command
{
    protected $signature = 'identity:issue-member-onboarding-invitations
        {--employee-id=* : Exact employee IDs in the approved onboarding cohort}
        {--apply : Deliver invitations after a successful preflight}
        {--confirm= : Required literal confirmation when using --apply}
        {--backup= : Current protected backup evidence file required when using --apply}
        {--backup-sha256= : SHA-256 of the backup evidence file}
        {--integrity-snapshot= : Current established-account integrity snapshot required when using --apply}
        {--integrity-sha256= : SHA-256 of the integrity snapshot}
        {--format=table : table or json}';

    protected $description = 'Dry-run or explicitly issue per-member MBFD Hub onboarding invitations.';

    public function handle(MemberOnboardingInvitationService $invitations, EstablishedAccountIntegritySnapshot $snapshots): int
    {
        $employeeIds = collect($this->option('employee-id'))
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();
        $apply = (bool) $this->option('apply');

        if ($employeeIds->isEmpty()) {
            return $this->report(['status' => 'invalid_cohort', 'reason' => 'at_least_one_employee_id_is_required'], Command::FAILURE);
        }
        if ($apply && ! $this->validApplyEvidence($snapshots)) {
            return Command::FAILURE;
        }

        $employees = Employee::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');
        $records = [];
        foreach ($employeeIds as $employeeId) {
            $matches = $employees->get($employeeId, collect());
            if ($matches->count() !== 1) {
                $records[] = ['status' => $matches->isEmpty() ? 'missing_employee' : 'duplicate_employee_id'];

                continue;
            }
            $records[] = $invitations->assess($matches->first());
        }

        $summary = $this->summary($records, $employeeIds->count());
        if (! $apply) {
            return $this->report($summary + ['mode' => 'dry_run'], $this->hasBlockingStatus($records) ? Command::FAILURE : Command::SUCCESS);
        }
        if ($this->hasBlockingStatus($records)) {
            return $this->report($summary + ['mode' => 'apply', 'reason' => 'preflight_failed_no_invitations_sent'], Command::FAILURE);
        }

        $now = CarbonImmutable::now();
        $delivery = [];
        foreach ($records as $record) {
            $employee = Employee::query()->findOrFail($record['employee_profile_id']);
            $user = $employee->user;
            if ($user === null) {
                return $this->report($summary + ['mode' => 'apply', 'reason' => 'identity_changed_before_issue'], Command::FAILURE);
            }
            try {
                $delivery[] = $invitations->issue($user, $now);
            } catch (Throwable) {
                return $this->report($summary + ['mode' => 'apply', 'reason' => 'identity_changed_or_delivery_failed'], Command::FAILURE);
            }
        }
        $sent = count(array_filter($delivery, static fn (string $status): bool => $status === 'queued'));
        $postVerified = MemberOnboardingInvitation::query()
            ->whereIn('employee_profile_id', array_filter(array_column($records, 'employee_profile_id')))
            ->where('security_version', '>', 0)
            ->whereNotNull('token_hash')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->where('delivery_status', 'queued')
            ->count();
        $result = $summary + [
            'mode' => 'apply',
            'invitations_queued' => $sent,
            'post_run_verified' => $postVerified,
        ];

        return $this->report($result, $sent === count($records) && $postVerified === count($records) ? Command::SUCCESS : Command::FAILURE);
    }

    /** @param array<int, array{status:string,user_id?:int|null,employee_profile_id?:int|null}> $records */
    private function hasBlockingStatus(array $records): bool
    {
        return collect($records)->contains(static fn (array $record): bool => $record['status'] !== 'ready');
    }

    /**
     * @param  array<int, array{status:string,user_id?:int|null,employee_profile_id?:int|null}>  $records
     * @return array{requested:int,ready:int,blocked:int,statuses:array<string,int>}
     */
    private function summary(array $records, int $requested): array
    {
        $statuses = collect($records)->countBy('status')->sortKeys()->all();

        return [
            'requested' => $requested,
            'ready' => $statuses['ready'] ?? 0,
            'blocked' => $requested - ($statuses['ready'] ?? 0),
            'statuses' => $statuses,
        ];
    }

    private function validApplyEvidence(EstablishedAccountIntegritySnapshot $snapshots): bool
    {
        if ((string) $this->option('confirm') !== 'ISSUE_MEMBER_ONBOARDING_INVITATIONS') {
            $this->error('The explicit onboarding invitation confirmation is required.');

            return false;
        }
        $path = (string) $this->option('backup');
        $expectedHash = strtolower((string) $this->option('backup-sha256'));
        if ($path === '' || ! is_file($path) || ! is_readable($path) || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
            $this->error('A readable backup evidence file and its SHA-256 are required.');

            return false;
        }
        if (! hash_equals($expectedHash, hash_file('sha256', $path))) {
            $this->error('The supplied backup evidence SHA-256 does not match the file.');

            return false;
        }

        $handle = fopen($path, 'rb');
        $header = $handle === false ? false : fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($header !== 'PGDMP' || filemtime($path) < CarbonImmutable::now()->subDay()->getTimestamp()) {
            $this->error('The backup evidence must be a current PostgreSQL custom-format dump.');

            return false;
        }

        $snapshotPath = (string) $this->option('integrity-snapshot');
        $snapshotHash = strtolower((string) $this->option('integrity-sha256'));
        if (! is_file($snapshotPath) || ! is_readable($snapshotPath)
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || ! hash_equals($snapshotHash, hash_file('sha256', $snapshotPath))) {
            $this->error('A current, matching established-account integrity snapshot is required.');

            return false;
        }
        try {
            $baseline = json_decode((string) file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($baseline)
                || ($baseline['schema'] ?? null) !== 'mbfd-established-account-integrity-v1'
                || ! is_array($baseline['accounts'] ?? null)
                || ! is_string($baseline['generated_at'] ?? null)
                || ($baseline['established_account_count'] ?? null) !== count($baseline['accounts'])
                || CarbonImmutable::parse($baseline['generated_at'])->lessThan(CarbonImmutable::now()->subDay())) {
                throw new \RuntimeException('The integrity snapshot is invalid or stale.');
            }
            $comparison = $snapshots->compare($baseline, $snapshots->capture());
            if ($comparison['unexpected_changes'] !== 0 || $comparison['newly_established_accounts'] !== 0) {
                throw new \RuntimeException('Established accounts changed after the integrity snapshot.');
            }
        } catch (Throwable) {
            $this->error('Established-account integrity verification failed. Refresh the protected snapshot and preflight.');

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $result */
    private function report(array $result, int $status): int
    {
        if ($this->option('format') === 'json') {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            foreach ($result as $key => $value) {
                $this->line(strtoupper($key).'='.json_encode($value, JSON_THROW_ON_ERROR));
            }
        }

        return $status;
    }
}
