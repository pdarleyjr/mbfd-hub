<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingCourseResource\Pages;

use App\Filament\Concerns\HasPageTableExportActions;
use App\Filament\Training\Resources\TrainingCourseResource;
use App\Filament\Training\Resources\TrainingSessionResource;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperRosterImport;
use App\Services\Exports\ExportableTable;
use App\Services\Training\TrainingPaperRosterService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewTrainingPaper extends Page implements ExportableTable, HasTable
{
    use HasPageTableExportActions;
    use InteractsWithTable;

    protected static string $resource = TrainingCourseResource::class;

    protected static string $view = 'filament.training.pages.course-paper';

    public TrainingCourse $course;

    protected function tableExportOwner(): TrainingCourse
    {
        return $this->course;
    }

    public function mount(int|string $record): void
    {
        $this->course = TrainingCourse::findOrFail($record);
        Gate::authorize('view', $this->course);
    }

    public function getTitle(): string
    {
        return $this->course->name.' — Paper rosters';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('modules')->label('Modules')->url(fn (): string => TrainingCourseResource::getUrl('modules', ['record' => $this->course])),
            Action::make('matrix')->label('Attendance matrix')->visible(fn (): bool => $this->course->track_attendance_matrix)
                ->url(fn (): string => TrainingCourseResource::getUrl('matrix', ['record' => $this->course])),
        ];
    }

    public function table(Table $table): Table
    {
        Gate::authorize('view', $this->course);

        return static::withExportActions($table->query(TrainingPaperAttendance::query()->whereHas('session', fn ($query) => $query->where('training_course_id', $this->course->id))->with(['session', 'employee', 'sourceImport']))
            ->columns([
                Tables\Columns\TextColumn::make('session.held_on')->label('Class date')->date()->sortable(),
                Tables\Columns\TextColumn::make('employee.name')->label('Verified member')->placeholder('Not linked'),
                Tables\Columns\TextColumn::make('raw_transcription')->label('Written name / transcription')->searchable()->wrap()
                    ->visible(fn (): bool => Gate::allows('update', $this->course)),
                Tables\Columns\TextColumn::make('source_page')->label('Page'),
                Tables\Columns\TextColumn::make('source_row')->label('Row / region'),
                Tables\Columns\TextColumn::make('review_status')->label('Review')->badge(),
            ])->filters([
                Tables\Filters\SelectFilter::make('review_status')->label('Review state')->options(['pending' => 'Pending review', 'approved' => 'Verified paper', 'excluded' => 'Excluded', 'voided' => 'Voided']),
            ])->actions([
                Tables\Actions\Action::make('source')->label('Original paper')->icon('heroicon-o-document')
                    ->url(fn (TrainingPaperAttendance $record): string => route('training.paper.source', ['import' => $record->training_paper_roster_import_id]).'#page='.$record->source_page)
                    // The viewer scrolls to the page in #page=N; the new tab is only the no-script fallback.
                    ->extraAttributes(['data-hub-viewer' => 'pdf', 'data-hub-viewer-title' => 'Original paper'])
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('review')->label('Review entry')->visible(fn (): bool => Gate::allows('update', $this->course))
                    ->fillForm(fn (TrainingPaperAttendance $record): array => ['expected_version' => $record->review_version])
                    ->modalDescription('Inspect the original page and verify the member against canonical personnel records. A paper review does not create an electronic signature or retrospective officer certification.')
                    ->form([
                        Select::make('decision')->required()->options(['approved' => 'Approve verified attendance', 'excluded' => 'Exclude duplicate / non-attendee', 'voided' => 'Void previous acceptance']),
                        TrainingSessionResource::employeeSelect('employee_profile_id', 'Verified employee'),
                        Toggle::make('verified')->label('I checked the original source and identity')->accepted()->required(),
                        Textarea::make('reason')->label('Evidence and reason')->required()->minLength(5)->maxLength(2000),
                        Hidden::make('expected_version')->required()->rules(['integer', 'min:0']),
                    ])->action(function (TrainingPaperAttendance $record, array $data): void {
                        Gate::authorize('update', $this->course);
                        abort_unless($record->session->training_course_id === $this->course->id, 404);
                        if (! ($data['verified'] ?? false)) {
                            throw ValidationException::withMessages(['verified' => 'Inspect the original and confirm the evidence first.']);
                        }
                        app(TrainingPaperRosterService::class)->review($record, auth()->user(), $data['decision'], isset($data['employee_profile_id']) ? (int) $data['employee_profile_id'] : null, $data['reason'], (int) $data['expected_version']);
                    }),
            ])->defaultSort('id')->paginationPageOptions([25, 50, 100])->emptyStateHeading('No paper roster entries')
            ->emptyStateDescription('Reviewed historical sources will appear here after a guarded paper import.'));
    }

    protected function getViewData(): array
    {
        Gate::authorize('view', $this->course);

        return ['imports' => TrainingPaperRosterImport::query()->whereHas('session', fn ($query) => $query->where('training_course_id', $this->course->id))->with('session')->get()];
    }
}
