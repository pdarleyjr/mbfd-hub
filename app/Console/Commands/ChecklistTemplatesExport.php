<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Services\ChecklistTemplates\ChecklistTemplateException;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the live baseline of a Daily Checkout checklist template as JSON. With --write it replaces
 * storage/checklists/{type}_checklist.json, so a developer can commit a published version back into
 * the repository (docs/checklist-template-editor.md section 8). Nothing in the database changes.
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

        $path = $resolver->templatePath($type);
        if (file_put_contents($path, $json) === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }
        $source = $live['source'] === 'published' && $live['version'] !== null
            ? 'published version v'.$live['version']->version_number
            : 'the repository file';
        $this->info("Wrote {$path} from {$source} (checklist hash {$live['hash']}).");

        return self::SUCCESS;
    }
}
