<?php

declare(strict_types=1);

namespace App\Console\Commands\Ems;

use App\Services\Ems\Import\SupplyWorkbookImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads the supply room workbook (starting counts, shelves, order levels, new items, form links).
 * A dry run unless --apply is given. Safe to run again: a second run changes nothing.
 */
final class ApplySupplyWorkbook extends Command
{
    protected $signature = 'ems:apply-supply-workbook
        {--apply : Write the changes. Without this the command only shows what it would do}
        {--file= : Workbook extraction JSON (default database/data/ems/supply_room_workbook_2026.json)}';

    protected $description = 'Apply the supply room workbook: starting counts, shelves, order levels and form links (dry run unless --apply)';

    public function handle(SupplyWorkbookImporter $importer): int
    {
        $path = $this->resolve((string) ($this->option('file') ?: 'database/data/ems/supply_room_workbook_2026.json'));
        $linksPath = dirname($path).DIRECTORY_SEPARATOR.'catalog_supply_links.json';
        $apply = (bool) $this->option('apply');

        try {
            $report = $importer->run($path, is_file($linksPath) ? $linksPath : null, $apply);
        } catch (Throwable $exception) {
            $this->error('Workbook import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Workbook extraction sha256 '.$report['sha256']);
        $this->table(['What', 'Number'], collect($report['counts'])->map(fn (int $n, string $what): array => [str_replace('_', ' ', $what), $n])->values()->all());
        if ($report['field_changes_by_field'] !== []) {
            $this->table(['Field changed', 'Items'], collect($report['field_changes_by_field'])->map(fn (int $n, string $f): array => [$f, $n])->values()->all());
        }
        if (is_array($report['links'] ?? null)) {
            $links = $report['links'];
            $this->line(sprintf('Form links: %d to write, %d not stocked to write, %d unchanged, %d kept (set by a person), %d skipped.',
                $links['written'], $links['not_stocked_written'], $links['unchanged'], $links['kept_person'], count($links['skipped'])));
        }
        $this->printExceptions($report['exceptions']);

        if ($report['noop'] ?? false) {
            $this->info('This workbook was already applied. Nothing was changed.');
        } elseif ($report['applied']) {
            $snapshot = $report['snapshot'];
            $this->info("Applied. Starting counts saved as snapshot version {$snapshot['version']} ({$snapshot['lines']} items).");
        } else {
            $this->warn('Dry run only: nothing was saved. Add --apply to write these changes.');
        }

        return self::SUCCESS;
    }

    /** Every exception, grouped, so nothing is hidden. @param list<array<string, mixed>> $exceptions */
    private function printExceptions(array $exceptions): void
    {
        $this->line('');
        $this->line('Exceptions ('.count($exceptions).')');
        foreach (collect($exceptions)->groupBy('group') as $group => $rows) {
            $this->line('');
            $this->line(str_replace('_', ' ', (string) $group).' ('.$rows->count().')');
            foreach ($rows as $row) {
                $this->line(sprintf('  %s | %s | %s -> %s', $row['where'], $row['item'], $row['detail'], $row['action']));
            }
        }
    }

    private function resolve(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : base_path($path);
    }
}
