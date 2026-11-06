<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationResource\Pages;

use App\Filament\Resources\StationResource;
use App\Models\Station;
use App\Models\StationCleaningTask;
use App\Models\User;
use App\Services\Stations\StationCleaningSchedule;
use App\Services\Stations\StationCleaningScheduleEditor;
use App\Support\Stations\StationCleaningAccess;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Edit the station cleaning schedule from a station profile: the department focus for each weekday, the every-day
 * duties, and this station's own tasks and date exceptions, with a preview of any date. Only Super Administrators
 * and holders of stations.cleaning.manage can open it, and every save is checked again on the server.
 *
 * @property Form $form
 */
final class ManageStationCleaning extends Page
{
    use InteractsWithFormActions;
    use InteractsWithRecord;

    protected static string $resource = StationResource::class;

    protected static string $view = 'filament.stations.manage-station-cleaning';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return StationCleaningAccess::canManage(auth()->user())
            && (! $record instanceof Station || StationResource::canView($record));
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(self::canAccess(['record' => $this->getRecord()]), 403);
        $this->fillForm();
    }

    public function getTitle(): string
    {
        return 'Cleaning schedule: '.$this->station()->name;
    }

    public function getSubheading(): string
    {
        return 'Based on the '.StationCleaningSchedule::SOURCE_NOTE.'. Changes apply on the next page load, with a record of who changed what.';
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                $this->weeklySection(),
                $this->tasksSection(),
                $this->previewSection(),
            ]);
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && StationCleaningAccess::canManage($user), 403);
        $state = $this->form->getState();

        try {
            $changes = app(StationCleaningScheduleEditor::class)->save($user, $this->station(), $state);
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('The cleaning schedule was not saved')
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            return;
        }

        $this->fillForm();
        Notification::make()
            ->success()
            ->title($changes === 0 ? 'No changes to save' : 'Cleaning schedule saved')
            ->body($changes === 0 ? null : ($changes === 1 ? '1 change recorded.' : "{$changes} changes recorded."))
            ->send();
    }

    /** @return array<Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save schedule')->submit('save')->keyBindings(['mod+s']),
            Action::make('back')->label('Back to station')->color('gray')
                ->url(fn (): string => StationResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    private function fillForm(): void
    {
        $this->form->fill(app(StationCleaningScheduleEditor::class)->formState($this->station()) + [
            'preview_date' => app(StationCleaningSchedule::class)->calendarDay()->toDateString(),
        ]);
    }

    private function station(): Station
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Station, 404);

        return $record;
    }

    private function weeklySection(): Forms\Components\Section
    {
        $tabs = [];
        foreach (StationCleaningTask::WEEKDAYS as $weekday => $name) {
            $tabs[] = Forms\Components\Tabs\Tab::make($name)
                ->schema([Forms\Components\Group::make($this->fixedFields("Focus for {$name}"))->statePath("weekly.{$weekday}")]);
        }
        $tabs[] = Forms\Components\Tabs\Tab::make('Every day')
            ->schema([Forms\Components\Group::make($this->fixedFields('Every-day duties'))->statePath('baseline')]);

        return Forms\Components\Section::make('Weekly schedule (all stations)')
            ->description('The rotating focus for each day, and the duties done every day. These apply to every station unless a station has its own task for that day.')
            ->schema([Forms\Components\Tabs::make('weekly_tabs')->tabs($tabs)->contained(false)])
            ->collapsible();
    }

    /** @return list<Forms\Components\Component> */
    private function fixedFields(string $titleLabel): array
    {
        return [
            Forms\Components\Hidden::make('id'),
            Forms\Components\TextInput::make('title')
                ->label($titleLabel)
                ->maxLength(StationCleaningTask::TITLE_MAX)
                ->required(fn (Get $get): bool => filled($get('id')))
                ->helperText(fn (Get $get): ?string => filled($get('id')) ? null : 'No assignment yet. Add a title to create one.'),
            Forms\Components\Textarea::make('instructions')
                ->label('Instructions')
                ->rows(4)
                ->maxLength(StationCleaningTask::INSTRUCTIONS_MAX)
                ->helperText('One instruction per line. Crews see the first line, then can show the rest.'),
            Forms\Components\Toggle::make('is_enabled')->label('Shown to crews')->default(true),
        ];
    }

    private function tasksSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Station tasks and date exceptions')
            ->description("This station's own tasks, plus additional tasks and one-date changes for all stations. A one-date focus replaces that day's usual focus; an added task appears alongside it. Turn a task off instead of removing it, so its history is kept.")
            ->schema([
                Forms\Components\Repeater::make('tasks')
                    ->hiddenLabel()
                    ->schema($this->taskFields())
                    ->columns(['default' => 1, 'md' => 2])
                    ->deletable(false)
                    ->reorderable(false)
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => $this->taskLabel($state))
                    ->addActionLabel('Add a task or date exception')
                    ->defaultItems(0),
            ])
            ->collapsible();
    }

    /** @return list<Forms\Components\Component> */
    private function taskFields(): array
    {
        $stationName = $this->station()->name;

        return [
            Forms\Components\Hidden::make('id'),
            Forms\Components\Select::make('applies_to')
                ->label('Applies to')
                ->options([StationCleaningScheduleEditor::APPLIES_STATION => "{$stationName} only", StationCleaningScheduleEditor::APPLIES_ALL => 'All stations'])
                ->default(StationCleaningScheduleEditor::APPLIES_STATION)
                ->selectablePlaceholder(false)
                ->required(),
            Forms\Components\Select::make('kind')
                ->label('Type')
                ->options([StationCleaningTask::KIND_EXTRA => 'Add a task to the day', StationCleaningTask::KIND_FOCUS => "Replace the day's focus"])
                ->default(StationCleaningTask::KIND_EXTRA)
                ->selectablePlaceholder(false)
                ->required(),
            Forms\Components\Select::make('when')
                ->label('When')
                ->options([StationCleaningScheduleEditor::WHEN_WEEKDAY => 'Every week', StationCleaningScheduleEditor::WHEN_DATE => 'One date only'])
                ->default(StationCleaningScheduleEditor::WHEN_WEEKDAY)
                ->selectablePlaceholder(false)
                ->live()
                ->required(),
            Forms\Components\Select::make('weekday')
                ->label('Day')
                ->options(StationCleaningTask::WEEKDAYS)
                ->visible(fn (Get $get): bool => $get('when') !== StationCleaningScheduleEditor::WHEN_DATE)
                ->required(),
            Forms\Components\DatePicker::make('on_date')
                ->label('Date')
                ->visible(fn (Get $get): bool => $get('when') === StationCleaningScheduleEditor::WHEN_DATE)
                ->required(),
            Forms\Components\TextInput::make('title')
                ->label('Title')
                ->maxLength(StationCleaningTask::TITLE_MAX)
                ->required(),
            Forms\Components\Textarea::make('instructions')
                ->label('Instructions')
                ->rows(3)
                ->maxLength(StationCleaningTask::INSTRUCTIONS_MAX)
                ->helperText('One instruction per line.')
                ->columnSpanFull(),
            Forms\Components\Toggle::make('is_enabled')->label('Shown to crews')->default(true),
        ];
    }

    /** @param  array<string, mixed>  $state */
    private function taskLabel(array $state): string
    {
        $title = trim((string) ($state['title'] ?? '')) ?: 'New task';
        $when = ($state['when'] ?? null) === StationCleaningScheduleEditor::WHEN_DATE
            ? (filled($state['on_date'] ?? null) ? CarbonImmutable::parse((string) $state['on_date'])->format('M j, Y') : 'One date')
            : (StationCleaningTask::WEEKDAYS[(int) ($state['weekday'] ?? 0)] ?? 'Weekly');
        $scope = ($state['applies_to'] ?? null) === StationCleaningScheduleEditor::APPLIES_ALL ? 'All stations' : 'This station';
        $off = ($state['is_enabled'] ?? true) ? '' : ' (off)';

        return "{$title}: {$when}, {$scope}{$off}";
    }

    private function previewSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Preview a date')
            ->description('Shows what crews at this station see on the chosen date, from the saved schedule. Save first to preview changes.')
            ->schema([
                Forms\Components\DatePicker::make('preview_date')->label('Date')->live()->dehydrated(false),
                Forms\Components\Placeholder::make('preview')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => $this->previewHtml($get('preview_date'))),
            ])
            ->collapsible();
    }

    private function previewHtml(mixed $date): HtmlString
    {
        $day = filled($date) ? CarbonImmutable::parse((string) $date) : null;
        $schedule = app(StationCleaningSchedule::class)->forStation($this->station(), $day);
        $parts = ['<p><strong>'.e($schedule['date_label']).'</strong></p>'];
        $parts[] = $schedule['focus'] === null
            ? '<p>'.e((string) $schedule['note']).'</p>'
            : '<p>Focus: '.e($schedule['focus']['title']).($schedule['focus']['date_exception'] ? ' (this date only)' : '').'</p>'.$this->lines($schedule['focus']['instructions']);
        foreach ($schedule['extras'] as $extra) {
            $parts[] = '<p>Also: '.e($extra['title']).'</p>'.$this->lines($extra['instructions']);
        }
        if ($schedule['baseline'] !== null) {
            $parts[] = '<p>Every day:</p>'.$this->lines($schedule['baseline']['instructions']);
        }

        return new HtmlString(implode('', $parts));
    }

    /** @param  list<string>  $lines */
    private function lines(array $lines): string
    {
        return $lines === [] ? '' : '<ul style="list-style: disc; padding-left: 1.25rem;">'.implode('', array_map(static fn (string $line): string => '<li>'.e($line).'</li>', $lines)).'</ul>';
    }
}
