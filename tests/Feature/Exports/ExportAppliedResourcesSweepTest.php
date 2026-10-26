<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Concerns\HasExportActions;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Contracts\HasTable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/** Every registered resource table must render its list and produce both file types. */
final class ExportAppliedResourcesSweepTest extends ExportTestCase
{
    /** @return array<string, array{class-string<resource>}> */
    public static function resources(): array
    {
        $found = [];
        $app = dirname(__DIR__, 3).'/app';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app.'/Filament', \FilesystemIterator::SKIP_DOTS)) as $entry) {
            $file = $entry->getPathname();
            if (! str_ends_with($file, 'Resource.php')) {
                continue;
            }
            $relative = str_replace(['/', '\\'], '\\', substr($file, strlen($app) + 1, -4));
            $class = 'App\\'.$relative;
            if (class_exists($class) && is_subclass_of($class, Resource::class)
                && (new \ReflectionMethod($class, 'table'))->getDeclaringClass()->getName() !== Resource::class) {
                $found[$class] = [$class];
            }
        }
        ksort($found);

        return $found;
    }

    #[DataProvider('resources')]
    public function test_list_page_exports_excel_and_pdf_and_offers_bulk_export(string $resource): void
    {
        $admin = Filament::getPanel('admin');
        $panel = in_array($resource, $admin->getResources(), true) ? $admin : collect(Filament::getPanels())
            ->first(fn ($candidate): bool => in_array($resource, $candidate->getResources(), true));
        if ($panel === null) {
            self::markTestSkipped('This base resource is not registered in a panel: '.$resource);
        }
        Filament::setCurrentPanel($panel);
        self::assertContains(HasExportActions::class, class_uses_recursive($resource), 'A resource table is missing PDF and Excel exports: '.$resource);

        if (! $resource::canViewAny()) {
            self::assertFalse($resource::canExportTable(), 'An inaccessible resource must not offer export.');

            return;
        }

        $page = $resource::getPages()['index']->getPage();
        self::assertTrue(is_subclass_of($page, HasTable::class));
        $component = Livewire::test($page)->call('loadTable')
            ->assertSuccessful()
            ->assertTableActionVisible('export')
            ->assertTableBulkActionExists('export_selected');
        if ($component->instance()->getTable()->getFilter('created_between') !== null) {
            $model = $component->instance()->getTable()->getQuery()->getModel();
            self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), $model->getCreatedAtColumn()), $resource.' needs a real created-at column.');
        }

        foreach (['xlsx', 'pdf'] as $format) {
            $download = Livewire::test($page)->call('loadTable')
                ->callTableAction('export', data: ['format' => $format, 'scope' => 'all', 'include_toggled_off' => true])
                ->assertHasNoTableActionErrors();
            $bytes = $this->downloadedBytes($download);
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $bytes);
        }

        $component->assertSuccessful();
    }
}
