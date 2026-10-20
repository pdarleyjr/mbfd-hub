<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Training\TrainingPaperRosterService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ImportTrainingPaperRoster extends Command
{
    protected $signature = 'training:import-paper {--course-id=} {--module-id=} {--source-manifest=} {--reviewer=} {--dry-run} {--apply}';

    protected $description = 'Preview a private reviewed paper manifest; writes require explicit --apply';

    public function handle(TrainingPaperRosterService $service): int
    {
        try {
            if ($this->option('dry-run') && $this->option('apply')) {
                throw new \RuntimeException('Choose dry-run or apply.');
            }
            foreach (['course-id', 'module-id', 'reviewer'] as $flag) {
                if (! ctype_digit((string) $this->option($flag)) || (int) $this->option($flag) < 1) {
                    throw new \RuntimeException('Explicit positive course, module and reviewer IDs are required.');
                }
            }
            $path = realpath((string) $this->option('source-manifest'));
            if ($path === false || ! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
                throw new \RuntimeException('A bounded private manifest file is required.');
            }
            $manifest = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! is_array($manifest['documents'] ?? null)) {
                throw new \RuntimeException('Invalid manifest.');
            }
            foreach ($manifest['documents'] as $document) {
                if ((int) ($document['course_id'] ?? 0) !== (int) $this->option('course-id')
                    || (int) ($document['module_id'] ?? 0) !== (int) $this->option('module-id')) {
                    throw new \RuntimeException('Manifest does not match the explicit course and module IDs.');
                }
            }
            $reviewer = User::query()->findOrFail((int) $this->option('reviewer'));
            $result = $service->importManifest($manifest, $reviewer, (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            // Validation messages use schema keys only; never print manifest values or names.
            $this->error('Paper validation failed. Review the private manifest and source evidence.');

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Paper import failed ('.class_basename($exception).'). No successful import is claimed.');

            return self::FAILURE;
        }
    }
}
