<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ems\Import\SourceImporter;
use Illuminate\Console\Command;
use Throwable;

/** Imports the EMS bag forms and the Captain 5 supply room catalogue. Run by the lead at release; never seeds or wipes. */
final class ImportEmsSources extends Command
{
    protected $signature = 'ems:import-sources
        {--path=database/data/ems : Directory holding the three source JSON files}
        {--dry-run : Show what would change and roll everything back}';

    protected $description = 'Import the EMS bag inventory forms and the supply room catalogue (idempotent, never deletes history)';

    public function handle(SourceImporter $importer): int
    {
        $path = (string) $this->option('path');
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = base_path($path);
        }

        try {
            $result = $importer->import($path, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error('EMS import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['messages'] as $message) {
            $this->warn($message);
        }
        $this->table(['Result', 'Count'], collect($result['counts'])->map(fn (int $count, string $name): array => [$name, $count])->values()->all());
        $this->info($result['dry_run'] ? 'Dry run only: nothing was saved.' : 'EMS sources imported.');

        return self::SUCCESS;
    }
}
