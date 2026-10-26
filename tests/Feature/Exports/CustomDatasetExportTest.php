<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Admin\Pages\KnowledgeBase;
use App\Filament\Admin\Pages\MediaControlMedia;
use App\Filament\Admin\Pages\MedicalSupplyRoom;
use App\Filament\Admin\Pages\TrtTrailerInventory;
use App\Filament\Pages\PersonnelReconciliation;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\ViewTrainingMatrix;
use App\Models\PersonnelReconciliation as Report;
use App\Models\RagDocument;
use App\Models\SecurityActionEvent;
use App\Services\Training\TrainingRosterService;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class CustomDatasetExportTest extends ExportTestCase
{
    public static function simplePages(): array
    {
        return ['knowledge base' => [KnowledgeBase::class], 'TRT inventory' => [TrtTrailerInventory::class], 'media metadata' => [MediaControlMedia::class]];
    }

    #[DataProvider('simplePages')]
    public function test_custom_metadata_exports_both_formats_and_refuses_revoked_permission(string $pageClass): void
    {
        config()->set('services.media_control.ingest_token', '');
        $page = Livewire::test($pageClass);
        foreach (['xlsx', 'pdf'] as $format) {
            $page->callAction('exportData', ['format' => $format])->assertHasNoActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
        }
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse($page->instance()->canExportData($limited));
        $page->call('mountAction', 'exportData')->set('mountedActionsData.0.format', 'xlsx')->call('callMountedAction');
        self::assertArrayNotHasKey('download', $page->effects);
        self::assertSame(2, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'allowed')->count());
    }

    public function test_knowledge_base_values_and_formula_sanitization_are_preserved_in_excel(): void
    {
        RagDocument::query()->create(['filename' => '=Synthetic formula document', 'source_key' => 'synthetic-export-document', 'chunk_count' => 3,
            'chunk_ids' => [], 'size' => 2048, 'mime' => 'text/plain', 'status' => 'ready', 'uploaded_by' => $this->admin->id]);
        $page = Livewire::test(KnowledgeBase::class)->callAction('exportData', ['format' => 'xlsx']);
        $book = $this->workbook($page);
        self::assertSame("'=Synthetic formula document", $book->getSheet(0)->getCell('A2')->getValue());
        self::assertSame(3, $book->getSheet(0)->getCell('B2')->getValue());
        self::assertSame(2.0, $book->getSheet(0)->getCell('C2')->getValue());
    }

    public static function supplyTabs(): array
    {
        return array_combine($tabs = ['counts', 'reconcile', 'reorder', 'movements', 'snapshots'], array_map(static fn ($tab): array => [$tab], $tabs));
    }

    #[DataProvider('supplyTabs')]
    public function test_each_supply_room_tab_exports_both_formats_without_mutating_stock(string $tab): void
    {
        $page = Livewire::test(MedicalSupplyRoom::class)->set('tab', $tab);
        foreach (['xlsx', 'pdf'] as $format) {
            $page->callAction('exportData', ['format' => $format])->assertHasNoActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
        }
        self::assertSame(0, \App\Models\Ems\StockMovement::query()->count());
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse($page->instance()->canExportData($limited));
    }

    public function test_training_matrix_adds_pdf_and_excel_for_the_same_filtered_projection(): void
    {
        $course = app(TrainingRosterService::class)->createCourse(['name' => 'Synthetic export course', 'classifications' => ['EMS Training'], 'track_attendance_matrix' => true], $this->admin);
        $page = Livewire::test(ViewTrainingMatrix::class, ['record' => $course->id]);
        foreach (['xlsx', 'pdf'] as $format) {
            $page->callAction('exportData', ['format' => $format])->assertHasNoActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
        }
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse($page->instance()->canExportData($limited));
    }

    public function test_personnel_comparison_exports_both_formats_and_keeps_evidence_read_only(): void
    {
        $report = Report::query()->create(['uploaded_by' => $this->admin->id, 'source_name' => 'Synthetic.xlsx', 'workbook_sha256' => str_repeat('a', 64),
            'summary' => ['rules' => 'Synthetic comparison only', 'workbook_rows' => 1, 'hub_rows' => 1, 'present_both' => 1,
                'workbook_only' => 0, 'hub_only' => 0, 'conflicts' => 0, 'excluded' => 0, 'missing_email' => 1, 'sheets' => ['Synthetic'],
                'duplicate_ids' => 0, 'assignment_view_mismatches' => 0, 'assignment_overrides' => 0],
            'rows' => [['employee_id' => 'EXPORT-1', 'name' => 'Synthetic Member', 'classification' => 'matched',
                'differences' => [], 'workbook' => ['name' => 'Synthetic Member'], 'live' => ['name' => 'Synthetic Member'], 'flags' => [], 'qa_note' => 'Synthetic fixture']]]);
        $page = Livewire::test(PersonnelReconciliation::class)->assertSet('reportId', $report->id);
        foreach (['xlsx', 'pdf'] as $format) {
            $page->callAction('exportData', ['format' => $format])->assertHasNoActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
        }
        self::assertSame($report->rows, $report->fresh()->rows);
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse($page->instance()->canExportData($limited));
    }
}
