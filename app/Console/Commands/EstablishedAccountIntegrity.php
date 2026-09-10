<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use App\Services\Identity\MemberBootstrapCohortManifest;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class EstablishedAccountIntegrity extends Command
{
    protected $signature = 'identity:established-account-integrity
                            {--write= : New exclusive JSON snapshot path}
                            {--compare= : Existing baseline JSON snapshot path}
                            {--bootstrap-cohort-manifest= : Manifest whose proven pending cohort may leave the established snapshot}';

    protected $description = 'Write and optionally compare a redacted established-account integrity snapshot.';

    public function handle(EstablishedAccountIntegritySnapshot $snapshots, MemberBootstrapCohortManifest $manifests): int
    {
        $writePath = trim((string) $this->option('write'));
        $comparePath = trim((string) $this->option('compare'));
        if ($writePath === '') {
            $this->error('Snapshot blocked: --write=<exclusive-path> is required.');

            return self::FAILURE;
        }

        try {
            $current = $snapshots->capture();
            $this->writeExclusive($writePath, $current);
            $this->line('ESTABLISHED_ACCOUNTS='.$current['established_account_count']);
            $this->line('ESTABLISHED_ACCOUNT_SNAPSHOT='.$writePath);

            if ($comparePath === '') {
                return self::SUCCESS;
            }

            $baseline = $this->readSnapshot($comparePath);
            $manifestPath = trim((string) $this->option('bootstrap-cohort-manifest'));
            $allowedCohortUserIds = $manifestPath === ''
                ? []
                : array_column($manifests->load($manifestPath)['members'], 'user_id');
            $comparison = $snapshots->compare($baseline, $current, $allowedCohortUserIds);
            $this->line('ESTABLISHED_ALLOWED_BOOTSTRAP_COHORT_TRANSITIONS='.$comparison['allowed_bootstrap_cohort_transitions']);
            $this->line('ESTABLISHED_PASSWORD_HASHES_CHANGED='.$comparison['password_hashes_changed']);
            $this->line('ESTABLISHED_ACCOUNT_STATUS_CHANGED='.$comparison['account_status_changed']);
            $this->line('ESTABLISHED_MUST_CHANGE_STATE_CHANGED='.$comparison['must_change_state_changed']);
            $this->line('ESTABLISHED_ROLE_PERMISSION_CHANGES='.$comparison['role_permission_changes']);
            $this->line('ESTABLISHED_EMAIL_CHANGES='.$comparison['email_changes']);
            $this->line('ESTABLISHED_ACCOUNT_UNEXPECTED_CHANGES='.$comparison['unexpected_changes']);

            return $comparison['unexpected_changes'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Integrity snapshot failed safely: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function writeExclusive(string $path, array $snapshot): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new RuntimeException('Snapshot parent directory does not exist.');
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Snapshot path already exists or cannot be created.');
        }

        try {
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('Snapshot could not be written completely.');
            }
        } finally {
            fclose($handle);
        }
        if (! chmod($path, 0600)) {
            throw new RuntimeException('Snapshot permissions could not be restricted to mode 0600.');
        }
    }

    /** @return array{accounts:list<array<string,mixed>>} */
    private function readSnapshot(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Baseline snapshot could not be read.');
        }
        $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot)
            || ($snapshot['schema'] ?? null) !== 'mbfd-established-account-integrity-v1'
            || ! is_array($snapshot['accounts'] ?? null)) {
            throw new RuntimeException('Baseline snapshot schema is invalid.');
        }

        return $snapshot;
    }
}
