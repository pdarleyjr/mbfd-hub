<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Filament\Pages\PersonnelTableEditor;
use App\Models\PersonnelTable;
use App\Services\Personnel\PersonnelTableService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PersonnelTrackingHistoryExportTest extends ExportTestCase
{
    private function table(): PersonnelTable
    {
        return PersonnelTable::query()->create(['name' => 'Synthetic private table', 'visibility' => 'private',
            'membership_mode' => 'fixed', 'created_by' => $this->admin->id, 'source_columns' => ['name'],
            'columns' => [], 'retired_columns' => [], 'criteria' => [], 'version' => 1]);
    }

    public function test_both_personnel_pages_export_the_history_table_without_hidden_metadata(): void
    {
        $custom = $this->table();
        $master = app(PersonnelTableService::class)->master($this->admin);
        foreach ([[$custom, PersonnelTableEditor::class, ['table' => $custom->id]], [$master, MasterPersonnelDirectory::class, []]] as [$table, $pageClass, $parameters]) {
            $table->events()->create(['actor_user_id' => $this->admin->id, 'action' => 'columns_changed',
                'metadata' => ['fields' => ['completed'], 'tracking_values' => 'HIDDEN-TRACKING', 'contact' => 'HIDDEN-CONTACT'],
                'created_at' => CarbonImmutable::parse('2026-10-26T00:30:00Z')]);
            $page = Livewire::test($pageClass, $parameters)->mountAction('history')->assertSee('Export Excel')->assertSee('Export PDF');
            $page->call('exportPersonnelHistory', 'xlsx');
            $rows = $this->workbook($page)->getSheet(0)->toArray(null, true, true, false);
            self::assertSame('2026-10-25 20:30', $rows[1][0]);
            self::assertSame('Columns Changed', $rows[1][2]);
            self::assertSame('completed', $rows[1][3]);
            $page->call('exportPersonnelHistory', 'pdf');
            $text = (new Parser)->parseContent($this->downloadedBytes($page))->getText();
            self::assertStringContainsString('Oct 25, 2026 8:30 PM', $text);
            self::assertStringNotContainsString('HIDDEN-TRACKING', $text);
            self::assertStringNotContainsString('HIDDEN-CONTACT', $text);
            self::assertSame(1, $table->events()->count());
        }
    }

    public function test_private_table_visibility_is_rechecked_before_download(): void
    {
        $table = $this->table();
        $page = Livewire::test(PersonnelTableEditor::class, ['table' => $table->id])->instance();
        $other = $this->userWith('admin.access', 'personnel.tables.view', 'personnel.tables.export');
        $this->actingAs($other);
        self::assertFalse($page->canExportPersonnelHistory());
        foreach (['xlsx', 'pdf'] as $format) {
            try {
                $page->exportPersonnelHistory($format);
                self::fail('Another administrator received a private table history.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
    }
}
