<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources;

use App\Filament\Concerns\HasExportActions;
use App\Filament\Training\Resources\TrainingSessionResource\Pages;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Services\PersonnelRequests\PersonnelRosterSearch;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrainingSessionResource extends Resource
{
    use HasExportActions;

    protected static ?string $model = TrainingSession::class;

    protected static ?string $navigationGroup = 'Training';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Rosters';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'training-rosters';

    public static function employeeSelect(string $name, string $label): Select
    {
        return Select::make($name)->label($label)->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $options = app(PersonnelRosterSearch::class)->options($search);
                $activeIds = Employee::query()->where('roster_status', 'active')->whereIn('id', array_keys($options))->pluck('id')->all();

                return array_intersect_key($options, array_flip($activeIds));
            })
            ->getOptionLabelUsing(function ($value): ?string {
                $employee = Employee::find($value);

                return $employee ? app(PersonnelRosterSearch::class)->label($employee) : null;
            })->searchPrompt('Search by name or employee ID')->optionsLimit(100);
    }

    public static function sessionFields(): array
    {
        return [
            TextInput::make('course_name')->label('Course name for this session')->required()->maxLength(200),
            DatePicker::make('held_on')->label('Class date')->required()->default(fn (): string => now('America/New_York')->toDateString()),
            DateTimePicker::make('starts_at')->label('Start time (Eastern)')->timezone('America/New_York')->seconds(false),
            TextInput::make('location')->maxLength(255),
            self::employeeSelect('oic_employee_profile_id', 'Lead instructor / OIC')
                ->helperText('Optional suggested lead for this session. Confirm or change the lead and collect their signature when submitting the roster.'),
            TagsInput::make('classifications')->suggestions(fn (): array => TrainingCourse::classificationSuggestions())
                ->mutateStateForValidationUsing(fn ($state) => is_array($state) ? TrainingCourse::normalizeClassifications($state) : $state)
                ->required()->rules(['array', 'min:1', 'max:16'])
                ->nestedRecursiveRules(['bail', 'required', 'string', 'max:80', 'regex:/\S/u', 'distinct:ignore_case'])
                ->helperText('Choose a suggestion or type a new classification and press Enter. Saved classifications are reusable and shown in the public class list. Up to 16 classifications, 80 characters each.')
                ->columnSpanFull(),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('training_course_id')->label('Course')->options(fn (): array => TrainingCourse::orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->required()->live()->afterStateUpdated(function ($state, Set $set): void {
                    $course = TrainingCourse::find($state);
                    $set('course_name', $course?->name);
                    $set('classifications', $course->classifications ?? []);
                    $set('location', $course?->default_location);
                    $set('training_module_id', null);
                })->helperText('Create a course under Training → Courses if it is not listed.'),
            Select::make('training_module_id')->label('Module')->placeholder('Unassigned')
                ->options(fn (Get $get): array => \App\Models\Training\TrainingModule::query()->where('training_course_id', $get('training_course_id'))->orderBy('sort_order')->pluck('name', 'id')->all())
                ->helperText('Choose the module delivered in this session. Each date or delivery keeps its own roster.'),
            ...self::sessionFields(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions($table->modifyQueryUsing(fn (Builder $query) => $query->withCount([
            'attendances as active_attendances_count' => fn (Builder $q) => $q->whereNull('voided_at'),
            'paperAttendances as approved_paper_attendances_count' => fn (Builder $q) => $q->where('review_status', 'approved')->where('evidence_present', true)
                ->whereNotNull('employee_profile_id')->whereNotNull('reviewed_by')->whereNotNull('reviewed_at'),
        ]))
            ->columns([
                Tables\Columns\TextColumn::make('course_name')->label('Course')->searchable()->weight('semibold'),
                Tables\Columns\TextColumn::make('module.name')->label('Module')->placeholder('Unassigned'),
                Tables\Columns\TextColumn::make('held_on')->label('Date')->date()->sortable(),
                Tables\Columns\TextColumn::make('starts_at')->label('Time')->time('g:i A')->timezone('America/New_York'),
                Tables\Columns\TextColumn::make('location')->toggleable(),
                Tables\Columns\TextColumn::make('classifications')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('attendee_count')->label('Attendees')
                    ->getStateUsing(fn (TrainingSession $record): int => (int) $record->getAttribute($record->isPaper() ? 'approved_paper_attendances_count' : 'active_attendances_count')),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'open' => 'success', 'submitted', 'certified' => 'info', 'closed' => 'warning', default => 'gray',
                }),
            ])->groups([Group::make('course.name')->label('Course')->collapsible()])->defaultGroup('course.name')
            ->filters([
                Tables\Filters\SelectFilter::make('training_course_id')->label('Course')->relationship('course', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('status')->options(array_combine(['draft', 'open', 'closed', 'submitted', 'certified', 'archived', 'paper'], ['Draft', 'Open', 'Closed', 'Submitted', 'Certified', 'Archived', 'Paper'])),
                Tables\Filters\SelectFilter::make('classification')->options(fn (): array => array_combine($labels = TrainingCourse::classificationSuggestions(), $labels))
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $value) => $q->whereJsonContains('classifications', $value))),
                Tables\Filters\Filter::make('dates')->form([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('held_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('held_on', '<=', $date))),
            ])->actions([
                Tables\Actions\ViewAction::make()->label('Manage'),
                Tables\Actions\Action::make('pdf')->label('Download PDF')->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (TrainingSession $record): bool => $record->attendance_source !== 'paper')
                    ->url(fn (TrainingSession $record): string => route('training.rosters.export', ['session' => $record, 'format' => 'pdf'])),
                Tables\Actions\Action::make('xlsx')->label('Download Excel')->icon('heroicon-o-table-cells')
                    ->visible(fn (TrainingSession $record): bool => $record->attendance_source !== 'paper')
                    ->url(fn (TrainingSession $record): string => route('training.rosters.export', ['session' => $record, 'format' => 'xlsx'])),
            ])->defaultSort('held_on', 'desc')->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('No training sessions yet')->emptyStateDescription('Create a session, open sign-in, and launch the classroom roster.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrainingSessions::route('/'),
            'create' => Pages\CreateTrainingSession::route('/create'),
            'view' => Pages\ViewTrainingSession::route('/{record}'),
        ];
    }
}
