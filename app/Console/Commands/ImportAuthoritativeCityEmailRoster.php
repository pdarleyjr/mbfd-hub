<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use App\Services\Identity\AuthoritativeCityEmailRosterImporter;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ImportAuthoritativeCityEmailRoster extends Command
{
    protected $signature = 'identity:import-authoritative-city-emails
        {file : Complete authoritative CSV containing Employee ID and Email Address columns}
        {--apply : Import only unambiguous pending canonical accounts}
        {--confirm= : Required literal confirmation when using --apply}
        {--source-sha256= : SHA-256 of the exact owner-approved CSV required when using --apply}
        {--backup= : Current protected PostgreSQL backup required when using --apply}
        {--backup-sha256= : SHA-256 of the backup file}
        {--integrity-snapshot= : Current established-account integrity snapshot}
        {--integrity-sha256= : SHA-256 of the integrity snapshot}
        {--format=table : table or json}';

    protected $description = 'Dry-run or explicitly bind authoritative Employee ID and City email pairs for pending member onboarding; never sends email.';

    public function handle(AuthoritativeCityEmailRosterImporter $importer, EstablishedAccountIntegritySnapshot $snapshots): int
    {
        try {
            $preview = $importer->preview((string) $this->argument('file'));
        } catch (Throwable) {
            $this->error('The roster CSV could not be validated. No identities were changed.');

            return self::FAILURE;
        }
        $summary = [
            'source_sha256' => $preview['source_sha256'],
            'requested' => count($preview['records']),
            'statuses' => $preview['statuses'],
            'exceptions' => collect($preview['records'])
                ->filter(static fn (array $row): bool => ! in_array($row['status'], ['ready', 'unchanged'], true))
                ->map(static fn (array $row): array => ['employee_id' => $row['employee_id'], 'status' => $row['status']])
                ->values()->all(),
        ];
        $blocked = array_diff(array_keys($preview['statuses']), ['ready', 'unchanged', 'established_skipped', 'inactive_skipped', 'missing_city_email']);
        if (! $this->option('apply')) {
            $this->report($summary + ['mode' => 'dry_run']);

            return $blocked === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($blocked !== []) {
            $this->report($summary + ['mode' => 'apply', 'reason' => 'conflicts_blocked_no_changes']);

            return self::FAILURE;
        }
        $expectedSourceHash = strtolower((string) $this->option('source-sha256'));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSourceHash) !== 1
            || ! hash_equals($expectedSourceHash, $preview['source_sha256'])) {
            $this->error('The CSV does not match the explicitly approved source SHA-256. No identities were changed.');

            return self::FAILURE;
        }
        $baseline = $this->validApplyEvidence($snapshots);
        if ($baseline === null) {
            return self::FAILURE;
        }

        try {
            $applied = $importer->apply($preview);
            $comparison = $snapshots->compare($baseline, $snapshots->capture());
            $verified = 0;
            foreach ($preview['records'] as $row) {
                if (! in_array($row['status'], ['ready', 'unchanged'], true)) {
                    continue;
                }
                $employee = Employee::query()->find($row['employee_profile_id']);
                $user = User::query()->find($row['user_id']);
                $binding = MemberOnboardingRosterBinding::query()->where('employee_profile_id', $row['employee_profile_id'])->first();
                if ($employee?->employee_id === $row['employee_id']
                    && $employee->city_email === $row['city_email']
                    && $user?->email === $row['city_email']
                    && $binding?->employee_id === $row['employee_id']
                    && $binding->city_email === $row['city_email']
                    && $binding->source_sha256 === $preview['source_sha256']) {
                    $verified++;
                }
            }
            $success = $comparison['unexpected_changes'] === 0
                && $comparison['newly_established_accounts'] === 0
                && $verified === ($preview['statuses']['ready'] ?? 0) + ($preview['statuses']['unchanged'] ?? 0);
            $this->report($summary + ['mode' => 'apply', 'applied' => $applied, 'post_run_verified' => $verified,
                'established_account_integrity' => $success ? 'preserved' : 'failed']);

            return $success ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('The roster import stopped; inspect state before retrying. No email was sent.');

            return self::FAILURE;
        }
    }

    /** @return array<string,mixed>|null */
    private function validApplyEvidence(EstablishedAccountIntegritySnapshot $snapshots): ?array
    {
        if ((string) $this->option('confirm') !== 'IMPORT_AUTHORITATIVE_CITY_EMAIL_ROSTER') {
            $this->error('Explicit roster import confirmation is required.');

            return null;
        }
        $backup = (string) $this->option('backup');
        $backupHash = strtolower((string) $this->option('backup-sha256'));
        if ($backup === '' || ! is_file($backup) || ! is_readable($backup)
            || preg_match('/^[a-f0-9]{64}$/D', $backupHash) !== 1
            || ! hash_equals($backupHash, hash_file('sha256', $backup))) {
            $this->error('A readable backup and its matching SHA-256 are required.');

            return null;
        }
        $handle = fopen($backup, 'rb');
        $header = $handle === false ? false : fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($header !== 'PGDMP' || filemtime($backup) < CarbonImmutable::now()->subDay()->getTimestamp()) {
            $this->error('The backup must be a current PostgreSQL custom-format dump.');

            return null;
        }
        $snapshot = (string) $this->option('integrity-snapshot');
        $snapshotHash = strtolower((string) $this->option('integrity-sha256'));
        if ($snapshot === '' || ! is_file($snapshot) || ! is_readable($snapshot)
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || ! hash_equals($snapshotHash, hash_file('sha256', $snapshot))) {
            $this->error('A current established-account integrity snapshot and matching SHA-256 are required.');

            return null;
        }
        try {
            $baseline = json_decode((string) file_get_contents($snapshot), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($baseline)
                || ($baseline['schema'] ?? null) !== 'mbfd-established-account-integrity-v1'
                || ! is_array($baseline['accounts'] ?? null)
                || ! is_string($baseline['generated_at'] ?? null)
                || ($baseline['established_account_count'] ?? null) !== count($baseline['accounts'])
                || CarbonImmutable::parse($baseline['generated_at'])->lessThan(CarbonImmutable::now()->subDay())) {
                throw new \RuntimeException('Invalid established-account integrity snapshot.');
            }
            $comparison = $snapshots->compare($baseline, $snapshots->capture());
            if ($comparison['unexpected_changes'] !== 0 || $comparison['newly_established_accounts'] !== 0) {
                throw new \RuntimeException('Established accounts changed after the integrity snapshot.');
            }

            return $baseline;
        } catch (Throwable) {
            $this->error('Established-account integrity verification failed. Refresh the protected snapshot.');

            return null;
        }
    }

    /** @param array<string,mixed> $result */
    private function report(array $result): void
    {
        if ($this->option('format') === 'json') {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($result as $key => $value) {
            $this->line(strtoupper($key).'='.json_encode($value, JSON_THROW_ON_ERROR));
        }
    }
}
