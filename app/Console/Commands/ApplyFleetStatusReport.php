<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fleet\FleetStatusReportReconciler;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ApplyFleetStatusReport extends Command
{
    protected $signature = 'fleet:apply-status-report
                            {--file= : Path to the report JSON (database/data/fleet-status-report-*.json)}
                            {--dry-run : Compare and print; writes nothing (the default)}
                            {--apply : Write the changes in one transaction and save the before values}
                            {--revert= : Restore a file saved by --apply, field by field, while each still holds the applied value}
                            {--json= : Also write the result as JSON to this path}';

    protected $description = 'Reconcile a Fleet status report against the apparatus rows it names (dry run unless --apply)';

    public function handle(FleetStatusReportReconciler $reconciler): int
    {
        $file = (string) $this->option('file');
        $revert = (string) $this->option('revert');
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($revert !== '') {
            if ($file !== '' || $dryRun || $apply) {
                $this->error('--revert stands alone: do not combine it with --file, --dry-run or --apply.');

                return self::FAILURE;
            }

            return $this->runRevert($reconciler, $revert);
        }
        if ($file === '') {
            $this->error('--file is required.');

            return self::FAILURE;
        }
        if ($dryRun && $apply) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        return $this->runReport($reconciler, $file, $apply);
    }

    private function runReport(FleetStatusReportReconciler $reconciler, string $file, bool $apply): int
    {
        try {
            $plan = $reconciler->plan($reconciler->parse($file));
            $result = $apply ? $reconciler->apply($plan, $file) : null;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Fleet status report failed and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $report = $reconciler->report($plan, $result, $file);
        $this->printReport($report);

        return $this->writeJson($report) ? self::SUCCESS : self::FAILURE;
    }

    private function runRevert(FleetStatusReportReconciler $reconciler, string $path): int
    {
        try {
            $result = $reconciler->revert($path);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Revert failed and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Reverted Fleet status report %s. Rows written: %d, fields restored: %d, already restored: %d.',
            $result['report_date'], $result['rows_written'], $result['fields_written'], $result['already_restored'],
        ));
        foreach ($result['skipped'] as $skip) {
            $this->warn(sprintf('Left alone: %s %s - %s', $skip['vehicle_number'], $skip['field'] ?? '', $skip['reason']));
        }

        return $this->writeJson(['mode' => 'reverted', 'file' => basename($path)] + $result) ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function printReport(array $report): void
    {
        $applied = $report['apply_result'] !== null;
        $this->line($applied ? '<info>APPLIED</info> - written in one transaction.' : '<comment>DRY RUN - nothing written.</comment>');
        $this->line("Fleet status report {$report['report_date']}. A truck not in the report is never touched.");

        $this->table(
            ['Vehicle', 'Unit', 'Result', 'Detail'],
            array_map(fn (array $row): array => [
                $row['vehicle_number'],
                $row['unit'] ?? $row['report_designation'] ?? '-',
                $row['outcome'],
                $this->detail($row),
            ], $report['rows']),
        );

        $totals = $report['totals'];
        $this->line(sprintf(
            'Change: %d | Discrepancy: %d | No change: %d',
            $totals[FleetStatusReportReconciler::OUTCOME_CHANGE],
            $totals[FleetStatusReportReconciler::OUTCOME_DISCREPANCY],
            $totals[FleetStatusReportReconciler::OUTCOME_NO_CHANGE],
        ));
        if ($applied) {
            $this->line('Changes written: '.$report['apply_result']['fields_written'].' field(s) on '.$report['apply_result']['rows_written'].' row(s).');
            if ($report['apply_result']['saved_to'] !== null) {
                $this->line('Before values saved to '.$report['apply_result']['saved_to']);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function detail(array $row): string
    {
        $parts = [];
        if ($row['changes'] !== []) {
            $parts[] = FleetStatusReportReconciler::describeChanges($row['changes']);
            if (! $row['linked']) {
                $parts[] = 'no linked vehicle record, so no vehicle history row';
            }
        }

        return implode('; ', [...$parts, ...$row['discrepancies']]) ?: '-';
    }

    /** @param array<string, mixed> $report */
    private function writeJson(array $report): bool
    {
        $path = (string) $this->option('json');
        if ($path === '') {
            return true;
        }

        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new InvalidArgumentException("Cannot create report directory: {$directory}");
            }
            if (file_put_contents($path, $json.PHP_EOL) === false) {
                throw new InvalidArgumentException("Cannot write report: {$path}");
            }
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->error('JSON report not written: '.$exception->getMessage());

            return false;
        }

        $this->line("JSON report written to {$path}");

        return true;
    }
}
