<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IdentityReconciliation\IdentityReconciliationExporter;
use App\Services\IdentityReconciliation\IdentityReconciliationPreview;
use App\Services\IdentityReconciliation\InvalidOwnerLedger;
use App\Services\IdentityReconciliation\OwnerLedgerLoader;
use Illuminate\Console\Command;
use RuntimeException;

final class AuditIdentityReconciliation extends Command
{
    protected $signature = 'identity:reconcile-preview
                            {--format=table : Output format: table, json, or csv}
                            {--output= : New local artifact path; existing files are never overwritten}
                            {--approved-ledger= : Optional strict JSON owner-approved mapping ledger}';

    protected $description = 'Deterministic read-only User-to-Employee reconciliation preview; never applies links or changes data.';

    public function handle(
        OwnerLedgerLoader $ledgers,
        IdentityReconciliationPreview $preview,
        IdentityReconciliationExporter $exporter,
    ): int {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error('Invalid format. Allowed values: table, json, csv.');

            return self::FAILURE;
        }

        try {
            $ledgerPath = $this->option('approved-ledger');
            $ledger = $ledgers->load(is_string($ledgerPath) ? $ledgerPath : null);
            $report = $preview->build($ledger);
        } catch (InvalidOwnerLedger $exception) {
            $this->error('Invalid owner ledger: '.$exception->getMessage());

            return self::FAILURE;
        }
        $outputPath = $this->option('output');
        $hasOutputPath = is_string($outputPath) && $outputPath !== '';

        if ($format === 'table' && $hasOutputPath) {
            $this->error('Table output is console-only. Use --format=json or --format=csv with --output.');

            return self::FAILURE;
        }

        try {
            if ($format === 'table') {
                $this->line('Identity reconciliation preview: '.$report['gate_status']);
                $this->table(
                    ['Entity', 'DB ID', 'Employee/login ID', 'Name', 'Classification', 'Proposed action', 'Blocked reason'],
                    $exporter->tableRows($report),
                );
                $this->table(
                    ['Users', 'Employees', 'Exact matches', 'Unmatched Users', 'Unmatched Employees', 'Collisions', 'Owner review rows'],
                    [[
                        $report['summary']['total_users'],
                        $report['summary']['total_employees'],
                        $report['summary']['exact_matches'],
                        $report['summary']['unmatched_users'],
                        $report['summary']['unmatched_employees'],
                        $report['summary']['collisions'],
                        $report['summary']['owner_review_required_rows'],
                    ]],
                );
                $this->line('Snapshot token: '.$report['snapshot_token']);
            } else {
                $contents = $format === 'csv' ? $exporter->toCsv($report) : $exporter->toJson($report);
                if ($hasOutputPath) {
                    $exporter->writeExclusive($outputPath, $contents);
                    $this->info("Read-only identity preview written with restrictive permissions where supported: {$outputPath}");
                    $this->warn('This artifact contains personnel data. Do not commit it; delete it when owner review is complete.');
                } else {
                    $this->line(rtrim($contents, "\n"));
                }
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
