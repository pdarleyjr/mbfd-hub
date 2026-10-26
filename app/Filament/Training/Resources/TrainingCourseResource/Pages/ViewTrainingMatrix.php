<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingCourseResource\Pages;

use App\Filament\Concerns\HasDatasetExportActions;
use App\Filament\Training\Resources\TrainingCourseResource;
use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Services\Exports\ExportDataset;
use App\Services\Training\TrainingAttendanceMatrixService;
use App\Services\Training\TrainingMatrixExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewTrainingMatrix extends Page
{
    use HasDatasetExportActions;
    use WithPagination;

    public function canExportData(User $actor): bool
    {
        return $this->course->track_attendance_matrix && Gate::forUser($actor)->allows('view', $this->course);
    }

    protected function exportDataset(User $actor): ExportDataset
    {
        $this->authorizeMatrix();

        return app(TrainingMatrixExportService::class)->dataset($actor, $this->course, $this->filters, $this->display === 'sessions' ? 'sessions' : 'modules');
    }

    protected static string $resource = TrainingCourseResource::class;

    protected static string $view = 'filament.training.pages.course-matrix';

    public TrainingCourse $course;

    public array $filters = [];

    public string $display = 'modules';

    protected $queryString = ['filters', 'display'];

    public function mount(int|string $record): void
    {
        $this->course = TrainingCourse::findOrFail($record);
        $this->authorizeMatrix();
        $this->getForm('form')?->fill($this->filters);
    }

    public function getTitle(): string
    {
        return $this->course->name.' — Attendance matrix';
    }

    public function updatedFilters(): void
    {
        $this->resetPage();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('search')->label('Name or employee ID')->live(debounce: 400),
            Select::make('cohort')->label('Personnel')->options(['officers' => 'All officers', 'captain' => 'Captains', 'lieutenant' => 'Lieutenants', 'firefighters' => 'Firefighters'])->placeholder('All personnel')->live(),
            Select::make('assignment')->label('Assignment')->options(['rescue' => 'Rescue', 'combat' => 'Combat'])->placeholder('All assignments')->live(),
            Select::make('shift')->options(array_combine($shifts = ['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'], $shifts))->placeholder('All shifts')->live(),
            Select::make('de_bid')->label('Driver Engineer bid')->options(['1' => 'Holding a DE bid'])->placeholder('Any bid')->live(),
            Select::make('floating')->label('Floating assignment')->options(['1' => 'Floating only'])->placeholder('Any assignment')->live(),
            Select::make('module_id')->label('Module')->options(fn (): array => $this->course->modules()->orderBy('sort_order')->pluck('name', 'id')->all())->placeholder('All modules')->live(),
            Select::make('status')->label('Attendance')->options(['all' => 'All required covered', 'partial' => 'Partly covered', 'none' => 'None covered', 'missing' => 'Missing required training', 'provisional' => 'Provisional attendance', 'review' => 'Needs review'])->placeholder('Any status')->live(),
            Select::make('missing_module_id')->label('Missing module')->options(fn (): array => $this->course->modules()->orderBy('sort_order')->pluck('name', 'id')->all())->placeholder('Any module')->live(),
        ])->statePath('filters')->columns(['default' => 1, 'sm' => 2, 'xl' => 3]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->datasetExportAction(),
            Action::make('exportView')->label('Export current view')->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->download(false)),
            Action::make('exportComplete')->label('Complete workbook')->color('gray')
                ->action(fn (): StreamedResponse => $this->download(true)),
            Action::make('modules')->label('Modules')->color('gray')
                ->url(fn (): string => TrainingCourseResource::getUrl('modules', ['record' => $this->course])),
            Action::make('paper')->label('Paper rosters')->color('gray')
                ->url(fn (): string => TrainingCourseResource::getUrl('paper', ['record' => $this->course])),
        ];
    }

    public function download(bool $complete): StreamedResponse
    {
        $this->authorizeMatrix();
        $bytes = app(TrainingMatrixExportService::class)->bytes($this->course, $this->filters, $complete, $this->display === 'sessions' ? 'sessions' : 'modules');

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'training-course-'.$this->course->id.($complete ? '-complete' : '-current-view').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function getViewData(): array
    {
        $this->authorizeMatrix();
        $snapshot = app(TrainingAttendanceMatrixService::class)->snapshot($this->course, $this->filters);
        $page = $this->getPage();
        $rows = new LengthAwarePaginator(array_slice($snapshot['rows'], ($page - 1) * 50, 50), count($snapshot['rows']), 50, $page);

        return ['matrix' => $snapshot, 'rows' => $rows];
    }

    private function authorizeMatrix(): void
    {
        $this->course->refresh();
        Gate::authorize('view', $this->course);
        abort_unless($this->course->track_attendance_matrix, 404);
    }
}
