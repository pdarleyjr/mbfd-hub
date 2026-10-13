<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MemberActivationVerifier;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use App\Services\Identity\MemberActivationVerifierImporter;
use Illuminate\Console\Command;
use Throwable;

final class ImportMemberActivationVerifiers extends Command
{
    protected $signature = 'identity:import-member-activation-verifiers
        {file : Owner-approved CSV containing Employee ID, Date of Birth and Phone columns}
        {--apply : Store keyed digests for the ready rows}
        {--confirm= : Required literal confirmation when using --apply}
        {--source-sha256= : SHA-256 of the exact owner-approved CSV required when using --apply}
        {--format=table : table or json}';

    protected $description = 'Dry-run or explicitly load trusted date-of-birth and phone proofs for self-service activation; never prints or stores the plaintext values.';

    public function handle(MemberActivationVerifierImporter $importer, EstablishedAccountIntegritySnapshot $snapshots): int
    {
        try {
            $preview = $importer->preview((string) $this->argument('file'));
        } catch (Throwable) {
            $this->error('The activation records CSV could not be validated. Nothing was changed.');

            return self::FAILURE;
        }
        $importable = ['ready', 'unchanged'];
        $summary = [
            'source_sha256' => $preview['source_sha256'],
            'requested' => count($preview['records']),
            'statuses' => $preview['statuses'],
            // Reconciliation lists identifiers and reasons only, never the proofs.
            'exceptions' => collect($preview['records'])
                ->filter(static fn (array $row): bool => ! in_array($row['status'], $importable, true))
                ->map(static fn (array $row): array => ['employee_id' => $row['employee_id'], 'status' => $row['status']])
                ->values()->all(),
        ];
        if (! $this->option('apply')) {
            $this->report($summary + ['mode' => 'dry_run']);

            return self::SUCCESS;
        }
        if ((string) $this->option('confirm') !== 'IMPORT_MEMBER_ACTIVATION_VERIFIERS') {
            $this->error('Explicit import confirmation is required. Nothing was changed.');

            return self::FAILURE;
        }
        $expectedSourceHash = strtolower((string) $this->option('source-sha256'));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSourceHash) !== 1
            || ! hash_equals($expectedSourceHash, $preview['source_sha256'])) {
            $this->error('The CSV does not match the explicitly approved source SHA-256. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $baseline = $snapshots->capture();
            $applied = $importer->apply($preview);
            $comparison = $snapshots->compare($baseline, $snapshots->capture());
        } catch (Throwable) {
            $this->error('The import stopped and was rolled back. Nothing was changed.');

            return self::FAILURE;
        }
        // Members may legitimately activate while this runs; only changes to
        // accounts that were already established count as a failure.
        $preserved = $comparison['unexpected_changes'] === 0;
        $this->report($summary + [
            'mode' => 'apply',
            'applied' => $applied,
            'usable_verifiers' => MemberActivationVerifier::query()->whereNull('consumed_at')->whereNull('locked_at')->count(),
            'established_account_integrity' => $preserved ? 'preserved' : 'failed',
        ]);

        return $preserved ? self::SUCCESS : self::FAILURE;
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
