<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Finding H-04 — move sensitive files off the web-reachable public disk.
 *
 * Copies station inventory PDFs and workgroup shared uploads from the legacy
 * `public` disk to the configured private disk (filesystems.private). This is
 * ADDITIVE and SAFE: it copies (never deletes) and skips files that already
 * exist on the private disk. Run on the server AFTER deploying the code change.
 *
 *   php artisan storage:move-sensitive-private --dry-run   # preview
 *   php artisan storage:move-sensitive-private             # copy
 *   php artisan storage:move-sensitive-private --prune     # copy + remove
 *                                                          # public originals
 *                                                          # once verified
 */
class MoveSensitiveFilesToPrivate extends Command
{
    protected $signature = 'storage:move-sensitive-private
                            {--dry-run : Show what would be moved without copying}
                            {--prune : Delete the public original after a verified copy}';

    protected $description = 'Move sensitive files (inventory PDFs, workgroup shared uploads) from the public disk to the private disk.';

    /**
     * Directories on the public disk that hold sensitive files.
     */
    private const SENSITIVE_DIRECTORIES = [
        'inventory-submissions',
        'workgroup-shared-uploads',
    ];

    public function handle(): int
    {
        $privateDisk = config('filesystems.private', 'local');

        if ($privateDisk === 'public') {
            $this->error('filesystems.private resolves to the public disk — refusing to run. Set PRIVATE_FILESYSTEM_DISK.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');

        $this->info('Source disk: public');
        $this->info("Target disk: {$privateDisk}");
        $this->info($dryRun ? 'Mode: DRY RUN (no changes)' : ($prune ? 'Mode: COPY + PRUNE' : 'Mode: COPY'));
        $this->newLine();

        $copied = 0;
        $skipped = 0;
        $pruned = 0;
        $failed = 0;

        foreach (self::SENSITIVE_DIRECTORIES as $directory) {
            $files = Storage::disk('public')->allFiles($directory);

            if (empty($files)) {
                $this->line("  (no files under {$directory}/)");

                continue;
            }

            foreach ($files as $path) {
                if (Storage::disk($privateDisk)->exists($path)) {
                    $this->line("  = already private, skipped: {$path}");
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  + would copy: {$path}");
                    $copied++;

                    continue;
                }

                $contents = Storage::disk('public')->get($path);

                if ($contents === null) {
                    $this->warn("  ! could not read public file: {$path}");
                    $failed++;

                    continue;
                }

                Storage::disk($privateDisk)->put($path, $contents);

                // Verify the copy landed before considering it done.
                if (! Storage::disk($privateDisk)->exists($path)) {
                    $this->error("  ! copy verification failed: {$path}");
                    $failed++;

                    continue;
                }

                $this->line("  + copied: {$path}");
                $copied++;

                if ($prune) {
                    Storage::disk('public')->delete($path);
                    $this->line("  - pruned public original: {$path}");
                    $pruned++;
                }
            }
        }

        $this->newLine();
        $this->info("Copied: {$copied}  Skipped: {$skipped}  Pruned: {$pruned}  Failed: {$failed}");

        if ($failed > 0) {
            return self::FAILURE;
        }

        if (! $dryRun && ! $prune && $copied > 0) {
            $this->newLine();
            $this->comment('Public originals were left in place. After verifying downloads work, re-run with --prune to remove them.');
        }

        return self::SUCCESS;
    }
}
