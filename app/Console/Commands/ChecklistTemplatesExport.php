<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Captain5\MedicalScope;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Services\ChecklistTemplates\ChecklistTemplateException;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the live baseline of a Daily Checkout checklist template as JSON, so the published version can be
 * committed back into the repository (docs/checklist-template-editor.md section 8). With --write (refused in
 * production) it replaces storage/checklists/{type}_checklist.json of the local checkout. Nothing in the
 * database changes.
 */
final class ChecklistTemplatesExport extends Command
{
    protected $signature = 'checklist-templates:export
                            {type : The checklist type, for example engine or ladder1}
                            {--write : Replace the repository template file instead of printing it}';

    protected $description = 'Print the live Daily Checkout checklist template JSON, or write it to its repository file with --write';

    public function handle(ChecklistTemplateEditor $editor, DailyCheckoutChecklistResolver $resolver): int
    {
        $type = (string) $this->argument('type');
        if (! in_array($type, $editor->types(), true)) {
            $this->error("Unknown checklist type \"{$type}\". Choose one of: ".implode(', ', $editor->types()).'.');

            return self::FAILURE;
        }

        try {
            $live = $editor->live($type);
        } catch (ChecklistTemplateException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $json = $editor->exportJson($live['checklist']);

        if (! $this->option('write')) {
            $this->output->write($json, false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        // The repository file is deploy-managed: rewriting it on a running server would be lost on the next
        // deploy and would silently move Captain 5's reviewed medical baseline. Commit it from a checkout.
        if (app()->environment('production')) {
            $this->error("--write is refused in production. Run `php artisan checklist-templates:export {$type}` here (it only prints), "
                ."save the output as storage/checklists/{$type}_checklist.json in a repository checkout and commit it.");

            return self::FAILURE;
        }

        $path = $resolver->templatePath($type);
        if (file_put_contents($path, $json) === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }
        $source = $live['source'] === 'published' && $live['version'] !== null
            ? 'published version v'.$live['version']->version_number
            : 'the repository file';
        $this->info("Wrote {$path} from {$source} (checklist hash {$live['hash']}).");
        $reviewed = (string) config("captain5_medical_scope.{$type}.reviewed_version", '');
        if (in_array($type, MedicalScope::MEDICAL_ONLY_TYPES, true) && $reviewed !== ''
            && ! hash_equals($reviewed, MedicalScope::canonicalVersion($live['checklist']))) {
            $this->warn("Captain 5 medical scope was reviewed against the previous {$type} file. Re-review it and update "
                ."captain5_medical_scope.{$type}.reviewed_version before committing (tests/Unit/Captain5/MedicalScopeMappingTest fails until then).");
        }

        return self::SUCCESS;
    }
}
