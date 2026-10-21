<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fleet\Fy27FleetReconciler;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ReconcileFy27FleetAssets extends Command
{
    protected $signature = 'fleet:reconcile-fy27
                            {csv : Path to the staged FY27 fleet list CSV}
                            {--apply : Write safe enrichment (default is a dry run that writes nothing)}
                            {--report= : Also write a JSON report to this path (contains VINs and plates; keep it private)}';

    protected $description = 'Reconcile the FY27 city fleet list against the unit master vehicle table (dry run unless --apply)';

    /** Short labels used in the console table. */
    private const FIELD_LABELS = [
        'veh_number' => 'veh#', 'make' => 'make', 'model' => 'model', 'year' => 'year',
        'tag_number' => 'tag', 'serial_number' => 'VIN', 'dept_code' => 'dept',
        'employee_or_vehicle_name' => 'name', 'color' => 'color', 'fleet_status_code' => 'status',
        'fleet_class_code' => 'class', 'fleet_class_description' => 'class desc', 'asset_category' => 'category',
    ];

    private const VALUE_FIELDS = ['veh_number', 'make', 'model', 'year', 'tag_number', 'serial_number', 'dept_code'];

    public function handle(Fy27FleetReconciler $reconciler): int
    {
        $path = (string) $this->argument('csv');
        $apply = (bool) $this->option('apply');

        try {
            $rows = $reconciler->parse($path);
            $plan = $reconciler->plan($rows);
            $applyResult = $apply ? $reconciler->apply($plan) : null;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('FY27 reconciliation failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $report = $reconciler->report($plan, $applyResult, $path);
        $this->printReport($report);

        $reportPath = (string) $this->option('report');
        if ($reportPath !== '' && ! $this->writeReport($reportPath, $report)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function printReport(array $report): void
    {
        $applied = $report['apply_result'] !== null;
        $this->newLine();
        $this->line($applied ? '<info>APPLIED</info> - changes written in one transaction.' : '<comment>DRY RUN - no changes written.</comment>');
        $this->newLine();

        $this->line("FY27 rows: {$report['csv_rows']}");
        foreach ($report['totals'] as $class => $count) {
            $this->line(sprintf('  %-38s %d', $class, $count));
        }
        $sumOk = $report['totals_sum'] === $report['csv_rows'];
        $this->line(sprintf('  %-38s %d (%s)', 'Total classified', $report['totals_sum'], $sumOk ? 'equals CSV row count' : 'MISMATCH with CSV row count'));
        $this->line("Hidden rows (row_hidden=1): {$report['hidden_rows']} - included in the comparison.");

        $counts = $report['counts'];
        $this->line(sprintf(
            'Fills: %d | Refinements: %d | Proposals (new): %d | Proposals (already pending): %d | Creates: %d',
            $counts['fills'], $counts['refinements'], $counts['proposals_new'], $counts['proposals_already_pending'], $counts['creates'],
        ));
        if ($applied) {
            $result = $report['apply_result'];
            $this->line(sprintf(
                'Written now: %d applied change(s), %d proposal(s), %d created record(s), %d create(s) skipped (already present).',
                $result['applied'], $result['proposed'], $result['created'], $result['skipped_creates'],
            ));
        }

        $this->newLine();
        $this->table(
            ['Row', 'Vehicle No', 'Class', 'Changes'],
            array_map(fn (array $row): array => [
                $row['source_row'],
                $row['vehicle_no'] === '' ? '(blank)' : $row['vehicle_no'],
                $row['class'],
                $this->describeRow($row),
            ], $report['rows']),
        );

        $absent = array_map(
            fn (array $hub): string => $hub['veh_number'] !== null && trim((string) $hub['veh_number']) !== '' ? (string) $hub['veh_number'] : '(id '.$hub['id'].')',
            $report['absent_from_fy27'],
        );
        $this->line('Hub records absent from FY27 (never modified): '.count($absent));
        if ($absent !== []) {
            $this->line('  '.implode(', ', $absent));
        }
    }

    /** @param array<string, mixed> $row */
    private function describeRow(array $row): string
    {
        $parts = [];
        if ($row['create'] !== null) {
            $parts[] = 'create new record ('.($row['create']['asset_category'] ?? 'vehicle').')';
        }
        foreach ($row['changes'] as $change) {
            $label = self::FIELD_LABELS[$change['field']] ?? $change['field'];
            if (isset($change['name_differs'])) {
                $detail = 'name differs';
            } elseif (in_array($change['field'], self::VALUE_FIELDS, true)) {
                $detail = sprintf('%s: %s -> %s', $label, $change['old'] ?? '(blank)', $change['new']);
            } else {
                $detail = $label;
            }
            $tag = match ($change['kind']) {
                Fy27FleetReconciler::KIND_FILL => 'fill',
                Fy27FleetReconciler::KIND_REFINEMENT => 'refine',
                default => $change['already_pending'] ? 'propose, pending' : 'propose',
            };
            $parts[] = "{$detail} [{$tag}]";
        }
        foreach ($row['notes'] as $note) {
            $parts[] = "note: {$note}";
        }

        return $parts === [] ? '-' : implode('; ', $parts);
    }

    /** @param array<string, mixed> $report */
    private function writeReport(string $path, array $report): bool
    {
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new InvalidArgumentException("Cannot create report directory: {$directory}");
            }
            if (file_put_contents($path, $json.PHP_EOL) === false) {
                throw new InvalidArgumentException("Cannot write report: {$path}");
            }
        } catch (InvalidArgumentException|JsonException $e) {
            $this->error('Report not written: '.$e->getMessage());

            return false;
        }

        $this->line("JSON report written to {$path} (contains VINs and plates; store privately, do not commit).");

        return true;
    }
}
