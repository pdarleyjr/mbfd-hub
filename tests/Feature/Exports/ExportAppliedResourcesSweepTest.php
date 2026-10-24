<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Concerns\HasExportActions;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/** Every resource that adopted HasExportActions must render its list and produce both file types. */
final class ExportAppliedResourcesSweepTest extends ExportTestCase
{
    /** @return array<string, array{class-string<resource>}> */
    public static function resources(): array
    {
        $found = [];
        $app = dirname(__DIR__, 3).'/app';
        foreach (glob($app.'/Filament/{Resources,Training/Resources,Clusters/*/Resources}/*Resource.php', GLOB_BRACE) ?: [] as $file) {
            $relative = str_replace(['/', '\\'], '\\', substr($file, strlen($app) + 1, -4));
            $class = 'App\\'.$relative;
            if (class_exists($class) && in_array(HasExportActions::class, class_uses_recursive($class), true)) {
                $found[class_basename($class)] = [$class];
            }
        }
        ksort($found);

        return $found;
    }

    #[DataProvider('resources')]
    public function test_list_page_exports_excel_and_pdf_and_offers_bulk_export(string $resource): void
    {
        Filament::setCurrentPanel(Filament::getPanel(str_contains($resource, '\\Training\\') ? 'training' : 'admin'));

        if (! $resource::canViewAny()) {
            self::assertFalse($resource::canExportTable(), 'An inaccessible resource must not offer export.');

            return;
        }

        $page = $resource::getPages()['index']->getPage();
        $component = Livewire::test($page)->call('loadTable')
            ->assertSuccessful()
            ->assertTableActionVisible('export')
            ->assertTableBulkActionExists('export_selected');

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
