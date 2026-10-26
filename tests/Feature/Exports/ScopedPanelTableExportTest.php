<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Resources\UnitMasterVehicleResource\Pages\ReviewVehicleDocuments;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\ManageTrainingModules;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\ReviewTrainingPaper;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\ViewTrainingSession;
use App\Filament\Training\Widgets\TrainingTodoWidget;
use App\Filament\Workgroup\Pages\Evaluations;
use App\Filament\Workgroup\Pages\Files;
use App\Filament\Workgroup\Pages\Notes;
use App\Filament\Workgroup\Pages\SharedUploads;
use App\Filament\Workgroup\Pages\Surveys;
use App\Filament\Workgroup\Widgets\CategoryRankingsWidget;
use App\Filament\Workgroup\Widgets\FinalistsWidget;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Services\Exports\TableExportService;
use App\Services\Training\TrainingRosterService;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScopedPanelTableExportTest extends ExportTestCase
{
    public static function workgroupTables(): array
    {
        return array_combine($classes = [Evaluations::class, Files::class, Notes::class, SharedUploads::class, Surveys::class,
            CategoryRankingsWidget::class, FinalistsWidget::class], array_map(static fn ($class): array => [$class], $classes));
    }

    #[DataProvider('workgroupTables')]
    public function test_each_workgroup_table_exports_both_formats_and_rechecks_current_membership(string $class): void
    {
        Livewire::withoutLazyLoading();
        $member = $this->userWith();
        $group = Workgroup::query()->create(['name' => 'Synthetic scoped export group', 'created_by' => $this->admin->id]);
        WorkgroupMember::query()->create(['workgroup_id' => $group->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true]);
        WorkgroupSession::query()->create(['workgroup_id' => $group->id, 'name' => 'Synthetic scoped export session', 'start_date' => '2026-10-26', 'end_date' => '2026-10-27', 'status' => 'active']);
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        app(WorkgroupContext::class)->select($member, $group->id);
        $page = Livewire::test($class)->call('loadTable');
        foreach (['xlsx', 'pdf'] as $format) {
            $page->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered'])->assertHasNoTableActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
        }
        WorkgroupMember::query()->where('user_id', $member->id)->update(['is_active' => false]);
        self::assertFalse(app(TableExportService::class)->canExport($member, $class, $page->instance()));
    }

    public function test_training_module_paper_and_attendance_tables_export_both_formats(): void
    {
        $rosters = app(TrainingRosterService::class);
        $course = $rosters->createCourse(['name' => 'Synthetic training table exports', 'classifications' => ['EMS Training']], $this->admin);
        $session = $rosters->createSession($course, ['held_on' => '2026-10-26'], $this->admin);
        foreach ([ManageTrainingModules::class => $course->id, ReviewTrainingPaper::class => $course->id, ViewTrainingSession::class => $session->id] as $class => $record) {
            foreach (['xlsx', 'pdf'] as $format) {
                $page = Livewire::test($class, ['record' => $record])->call('loadTable')
                    ->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered'])->assertHasNoTableActionErrors();
                self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
            }
            $limited = $this->userWith('admin.access');
            $this->actingAs($limited);
            self::assertFalse(app(TableExportService::class)->canExport($limited, $class, $page->instance()));
            $this->actingAs($this->admin);
        }
    }

    public function test_training_widget_and_historical_document_review_export_both_formats(): void
    {
        Livewire::withoutLazyLoading();
        foreach ([TrainingTodoWidget::class, ReviewVehicleDocuments::class] as $class) {
            Filament::setCurrentPanel(Filament::getPanel($class === TrainingTodoWidget::class ? 'training' : 'admin'));
            foreach (['xlsx', 'pdf'] as $format) {
                $page = Livewire::test($class)->call('loadTable')
                    ->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered'])->assertHasNoTableActionErrors();
                self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
            }
            $limited = $this->userWith('admin.access');
            $this->actingAs($limited);
            self::assertFalse(app(TableExportService::class)->canExport($limited, $class, $page->instance()));
            $this->actingAs($this->admin);
        }
    }
}
