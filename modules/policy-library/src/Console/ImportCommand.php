<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Console;

use Illuminate\Console\Command;
use Mbfd\PolicyLibrary\Services\ImportService;

final class ImportCommand extends Command
{
    protected $signature = 'policy-library:import {manifest} {--root=} {--publish}';

    protected $description = 'Validate and stage policy library editions from an audited import manifest.';

    public function handle(ImportService $imports): int
    {
        $path = realpath((string) $this->argument('manifest'));
        if (! $path || ! is_file($path)) {
            $this->error('Import manifest not found.');

            return self::FAILURE;
        }
        try {
            $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $editions = $imports->stage($manifest, $this->option('root') ?: dirname($path));
            foreach ($editions as $edition) {
                if ($this->option('publish')) {
                    $imports->publish($edition, null);
                }
                $this->info($edition->manual->slug.': edition '.$edition->id.' '.($this->option('publish') ? 'published' : 'staged'));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Import failed validation. Published content was preserved.');

            return self::FAILURE;
        }
    }
}
