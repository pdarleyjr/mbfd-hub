<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChecklistTemplateResource\Pages;

use App\Filament\Resources\ChecklistTemplateResource;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use Filament\Resources\Pages\ListRecords;
use Throwable;

/**
 * Fleet > Checklist templates: one card per Daily Checkout checklist with where members get it from, the
 * open draft and the last publication, then the publication record table (which carries the shared exports).
 */
class ListChecklistTemplates extends ListRecords
{
    protected static string $resource = ChecklistTemplateResource::class;

    protected static string $view = 'filament.resources.checklist-template-resource.pages.list-checklist-templates';

    public function getSubheading(): ?string
    {
        return 'The equipment on each Daily Checkout apparatus checklist. Changes are drafted, previewed and published here; members see a change only after it is published.';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $templates = [];
        $error = null;
        try {
            $templates = app(ChecklistTemplateEditor::class)->overview();
        } catch (Throwable $exception) {
            report($exception);
            $error = 'The checklist templates could not be loaded. Try again; if it keeps happening, report it to the Hub administrators.';
        }

        return [
            'templates' => $templates,
            'overviewError' => $error,
            'canManage' => ChecklistTemplateResource::canManage(),
        ];
    }
}
