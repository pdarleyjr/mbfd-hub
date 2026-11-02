<?php

declare(strict_types=1);

namespace App\Console\Commands\Ems;

use App\Services\Ems\Import\SupplyEstimateLoader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads best guesses for items nobody has counted. A dry run unless --apply is given. Safe to run again.
 * Never touches an item that already has a number. The next real count of an item replaces its guess.
 */
final class ApplySupplyEstimates extends Command
{
    protected $signature = 'ems:apply-supply-estimates
        {--apply : Write the changes. Without this the command only shows what it would do}
        {--file= : Estimates JSON (default database/data/ems/supply_room_estimates_20261101.json)}';

    protected $description = 'Load best-guess numbers for supply room items nobody has counted (dry run unless --apply)';

    public function handle(SupplyEstimateLoader $loader): int
    {
        $path = (string) ($this->option('file') ?: base_path('database/data/ems/supply_room_estimates_20261101.json'));

        try {
            $report = $loader->run($path, (bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Estimates were not loaded: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Estimates file sha256 '.$report['sha256']);
        if ($report['noop']) {
            $this->info('This file was already applied. Nothing was changed.');

            return self::SUCCESS;
        }
        $this->table(['Item', 'Shows', 'Single units'], array_map(fn (array $row): array => [$row['name'], $row['shows'], $row['base']], $report['loaded']));
        $this->line('Moved or renamed: '.($report['moved'] === [] ? 'none' : implode(', ', $report['moved'])));
        $this->line('Kept, already has a number: '.($report['kept'] === [] ? 'none' : implode(', ', $report['kept'])));
        $report['applied'] ? $this->info(count($report['loaded']).' estimates saved.') : $this->warn('Dry run only: nothing was saved. Add --apply to write these changes.');

        return self::SUCCESS;
    }
}
