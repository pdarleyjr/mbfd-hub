<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use Illuminate\Console\Command;

/**
 * Where each Daily Checkout checklist template comes from right now: the repository file or a
 * published editor version, open drafts, and whether the repository file changed after the
 * published version was started from it (docs/checklist-template-editor.md section 8).
 */
final class ChecklistTemplatesStatus extends Command
{
    protected $signature = 'checklist-templates:status';

    protected $description = 'Show the source, published version, open draft and file drift of every Daily Checkout checklist template';

    public function handle(ChecklistTemplateEditor $editor): int
    {
        $rows = [];
        foreach ($editor->overview() as $template) {
            $rows[] = [
                $template['type'],
                $template['source'] === 'published' ? 'published' : 'repository file',
                $template['published'] === null
                    ? '-'
                    : 'v'.$template['published']['version_number'].' ('.($template['published']['published_at']?->toDateString() ?? '?').')',
                $template['draft'] === null
                    ? 'no'
                    : 'yes'.($template['draft']['has_changes'] ? ' (changed)' : '').' by '.($template['draft']['updated_by'] ?? 'unknown'),
                $template['file_drift'] ? 'yes' : 'no',
                $template['file_error'] ?? '-',
                (string) count($template['units']),
            ];
        }

        $this->table(['Type', 'Source', 'Published', 'Draft', 'File drift', 'File error', 'Units'], $rows);

        return self::SUCCESS;
    }
}
