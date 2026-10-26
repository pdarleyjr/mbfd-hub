<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Admin\Pages\MedicalDataStatistics;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Pages\MasterPersonnelDirectory;
use App\Filament\Pages\PersonnelTableEditor;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Services\Exports\ExportableTable;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Contracts\HasTable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\TableWidget;

/** A new registered surface cannot quietly miss the PDF/Excel capability sweep. */
final class PanelExportCoverageTest extends ExportTestCase
{
    public function test_every_registered_table_has_a_native_or_shared_export_contract(): void
    {
        $nativeTables = [MasterPersonnelDirectory::class, PersonnelTableEditor::class];
        foreach (['admin', 'training', 'workgroups'] as $id) {
            $panel = Filament::getPanel($id);
            foreach ($panel->getResources() as $resource) {
                $index = $resource::getPages()['index']->getPage();
                if (! is_subclass_of($index, HasTable::class)) {
                    self::assertSame(UserResource::class, $resource, 'Classify the native redirect or export the new resource table.');
                } else {
                    self::assertContains(HasExportActions::class, class_uses_recursive($resource), $resource.' must expose both shared formats.');
                }
                foreach ($resource::getRelations() as $relation) {
                    self::assertTrue(is_subclass_of($relation, ExportableTable::class), $relation.' requires parent-scoped export authorization.');
                }
            }
            $pages = $panel->getPages();
            foreach ($panel->getResources() as $resource) {
                foreach ($resource::getPages() as $registration) {
                    $pages[] = $registration->getPage();
                }
            }
            foreach (array_unique($pages) as $page) {
                if (is_subclass_of($page, HasTable::class) && ! is_subclass_of($page, ListRecords::class)) {
                    self::assertTrue(in_array($page, $nativeTables, true) || is_subclass_of($page, ExportableTable::class), $page.' is missing page-table exports.');
                }
            }
            foreach ($panel->getWidgets() as $configuration) {
                $widget = is_string($configuration) ? $configuration : $configuration->widget;
                if (is_subclass_of($widget, TableWidget::class)) {
                    self::assertTrue(is_subclass_of($widget, ExportableTable::class), $widget.' is missing widget-table exports.');
                }
                if (is_subclass_of($widget, ChartWidget::class)) {
                    self::assertTrue(method_exists($widget, 'canExportData') && method_exists($widget, 'exportDataset'), $widget.' needs an export of labels, values and its date range.');
                }
                if (! is_subclass_of($widget, TableWidget::class) && ! is_subclass_of($widget, ChartWidget::class)
                    && ! is_subclass_of($widget, StatsOverviewWidget::class)) {
                    self::assertTrue($widget === StationOperationsHubWidget::class
                        ? method_exists($widget, 'canExportBoard') && method_exists($widget, 'exportBoard')
                        : method_exists($widget, 'canExportData') && method_exists($widget, 'exportDataset'),
                        $widget.' needs explicit custom-widget dataset export coverage.');
                }
                if (is_subclass_of($widget, StatsOverviewWidget::class)) {
                    $source = file_get_contents((new \ReflectionClass($widget))->getFileName());
                    self::assertFalse(str_contains($source, '->chart('), $widget.' adds a trend chart; add its underlying-data export before registering it.');
                }
            }
        }
    }

    public function test_custom_registered_page_tables_and_charts_have_dataset_exports(): void
    {
        $native = [MedicalDataStatistics::class]; // Existing StatisticsExporter tests exercise PDF and Excel.
        foreach (['admin', 'training', 'workgroups'] as $id) {
            foreach (Filament::getPanel($id)->getPages() as $page) {
                if (is_subclass_of($page, HasTable::class)) {
                    continue;
                }
                $view = (new \ReflectionClass($page))->getDefaultProperties()['view'] ?? null;
                if (! is_string($view) || ! view()->exists($view)) {
                    continue;
                }
                $source = file_get_contents(view($view)->getPath());
                if (! preg_match('/<table\b|<canvas\b|wg-progress-fill/i', $source)) {
                    continue;
                }
                self::assertTrue(in_array($page, $native, true) || (method_exists($page, 'canExportData') && method_exists($page, 'exportDataset')),
                    $page.' renders a custom table or chart without PDF/Excel dataset export support.');
            }
        }
    }
}
