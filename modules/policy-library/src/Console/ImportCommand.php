<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
            if ($this->option('publish')) {
                DB::transaction(function () use ($imports, $editions): void {
                    foreach ($editions as $edition) {
                        $imports->publish($edition, null);
                    }
                });
            }
            foreach ($editions as $edition) {
                $this->info($edition->manual->slug.': edition '.$edition->id.' '.($this->option('publish') ? 'published' : 'staged'));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Import failed. Current published editions were preserved; any staged drafts remain private.');

            return self::FAILURE;
        }
    }
}
