<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingCourseResource\Pages;

use App\Filament\Concerns\HasPageTableExportActions;
use App\Filament\Training\Resources\TrainingCourseResource;
use App\Filament\Training\Resources\TrainingSessionResource;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Services\Exports\ExportableTable;
use App\Services\Training\TrainingCourseModuleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ManageTrainingModules extends Page implements ExportableTable, HasTable
{
    use HasPageTableExportActions;
    use InteractsWithTable;

    protected static string $resource = TrainingCourseResource::class;

    protected static string $view = 'filament.training.pages.course-modules';

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
        return $this->course->name.' — Modules';
    }

    public static function moduleFields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(200),
            Textarea::make('description')->maxLength(5000),
            TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
            Toggle::make('is_required')->label('Required module')->default(true),
            Select::make('audience.ranks')->label('Applies to ranks')->multiple()
                ->options(fn (): array => Employee::query()->whereNotNull('rank')->distinct()->orderBy('rank')->pluck('rank', 'rank')->all())
                ->helperText('Leave empty for all ranks.'),
            Select::make('audience.shifts')->label('Applies to shifts')->multiple()
                ->options(array_combine($shifts = ['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'], $shifts))
                ->helperText('Leave empty for all shifts. Unknown assignments are flagged for review.'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createModule')->label('Add module')->visible(fn (): bool => Gate::allows('update', $this->course))
                ->form(self::moduleFields())->action(function (array $data): void {
                    Gate::authorize('update', $this->course);
                    app(TrainingCourseModuleService::class)->createModule($this->course, $data, auth()->user());
                }),
            Action::make('sessions')->label('Sessions')->color('gray')
                ->url(fn (): string => TrainingSessionResource::getUrl('index', ['tableFilters' => ['training_course_id' => ['value' => $this->course->id]]])),
            Action::make('matrix')->label('Attendance matrix')->color('gray')
                ->visible(fn (): bool => $this->course->track_attendance_matrix)
                ->url(fn (): string => TrainingCourseResource::getUrl('matrix', ['record' => $this->course])),
            Action::make('course')->label('Course settings')->color('gray')
                ->visible(fn (): bool => Gate::allows('update', $this->course))
                ->url(fn (): string => TrainingCourseResource::getUrl('edit', ['record' => $this->course])),
        ];
    }

    public function table(Table $table): Table
    {
        Gate::authorize('view', $this->course);

        return static::withExportActions($table->query(TrainingModule::query()->where('training_course_id', $this->course->id))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                Tables\Columns\TextColumn::make('description')->wrap(),
                Tables\Columns\IconColumn::make('is_required')->label('Required')->boolean(),
                Tables\Columns\TextColumn::make('audience.ranks')->label('Ranks')->separator(', ')->placeholder('All ranks'),
                Tables\Columns\TextColumn::make('audience.shifts')->label('Shifts')->separator(', ')->placeholder('All shifts'),
            ])->actions([
                Tables\Actions\Action::make('editModule')->label('Edit')->visible(fn (): bool => Gate::allows('update', $this->course))
                    ->fillForm(fn (TrainingModule $record): array => $record->only(['name', 'description', 'sort_order', 'is_required', 'audience']))
                    ->form(self::moduleFields())->action(function (TrainingModule $record, array $data): void {
                        Gate::authorize('update', $this->course);
                        abort_unless($record->training_course_id === $this->course->id, 404);
                        app(TrainingCourseModuleService::class)->updateModule($record, $data, auth()->user());
                    }),
            ])->defaultSort('sort_order')->emptyStateHeading('Add the first module')
            ->emptyStateDescription('Each module can have any number of dated sessions. Existing unassigned sessions remain available.'));
    }
}
