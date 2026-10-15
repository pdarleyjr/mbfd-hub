<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources;

use App\Filament\Training\Resources\TrainingSessionResource\Pages;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Services\PersonnelRequests\PersonnelRosterSearch;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrainingSessionResource extends Resource
{
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
            self::employeeSelect('oic_employee_profile_id', 'Officer in charge')
                ->helperText('The officer must sign in with their own Hub account to certify the roster.'),
            CheckboxList::make('classifications')->options(array_combine(TrainingCourse::CLASSIFICATIONS, TrainingCourse::CLASSIFICATIONS))
                ->required()->columns(2)->columnSpanFull(),
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
                })->helperText('Create a course under Training → Courses if it is not listed.'),
            ...self::sessionFields(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->withCount(['attendances as active_attendances_count' => fn (Builder $q) => $q->whereNull('voided_at')]))
            ->columns([
                Tables\Columns\TextColumn::make('course_name')->label('Course')->searchable()->weight('semibold'),
                Tables\Columns\TextColumn::make('held_on')->label('Date')->date()->sortable(),
                Tables\Columns\TextColumn::make('starts_at')->label('Time')->time('g:i A')->timezone('America/New_York'),
                Tables\Columns\TextColumn::make('location')->toggleable(),
                Tables\Columns\TextColumn::make('classifications')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('active_attendances_count')->label('Attendees'),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'open' => 'success', 'submitted', 'certified' => 'info', 'closed' => 'warning', default => 'gray',
                }),
            ])->groups([Group::make('course.name')->label('Course')->collapsible()])->defaultGroup('course.name')
            ->filters([
                Tables\Filters\SelectFilter::make('training_course_id')->label('Course')->relationship('course', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('status')->options(array_combine(['draft', 'open', 'closed', 'submitted', 'certified', 'archived'], ['Draft', 'Open', 'Closed', 'Submitted', 'Certified', 'Archived'])),
                Tables\Filters\SelectFilter::make('classification')->options(array_combine(TrainingCourse::CLASSIFICATIONS, TrainingCourse::CLASSIFICATIONS))
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $value) => $q->whereJsonContains('classifications', $value))),
                Tables\Filters\Filter::make('dates')->form([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('held_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('held_on', '<=', $date))),
            ])->actions([
                Tables\Actions\ViewAction::make()->label('Manage'),
                Tables\Actions\Action::make('pdf')->label('Download PDF')->icon('heroicon-o-document-arrow-down')
                    ->url(fn (TrainingSession $record): string => route('training.rosters.export', ['session' => $record, 'format' => 'pdf'])),
                Tables\Actions\Action::make('xlsx')->label('Download Excel')->icon('heroicon-o-table-cells')
                    ->url(fn (TrainingSession $record): string => route('training.rosters.export', ['session' => $record, 'format' => 'xlsx'])),
            ])->defaultSort('held_on', 'desc')->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('No training sessions yet')->emptyStateDescription('Create a session, open sign-in, and launch the classroom roster.');
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
