<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5DutyAssignment;
use App\Models\Captain5\Captain5ItemClassification;
use App\Models\Employee;
use App\Models\User;
use App\Services\Captain5\Captain5AccessExplainer;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Captain5\Captain5AssignmentService;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\MedicalScopeReview;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Captain5Access;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Administrator surface for the Captain 5 EMS Operations Center: who can use it, access
 * overrides, the acting Captain 5 for a duty day, and the medical scope review.
 *
 * Every decision is made by Captain5Access / Captain5AdministrationService /
 * Captain5AssignmentService, which authorize again and write the history and audit rows. This
 * page only gathers input; hiding a control here is cosmetic.
 */
final class Captain5Operations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?string $navigationLabel = 'Captain 5 Operations';

    protected static ?string $title = 'Captain 5 Operations';

    protected static ?string $slug = 'captain5-operations';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.admin.pages.captain5-operations';

    private const SEARCH_MIN_LENGTH = 2;

    private const HISTORY_LIMIT = 60;

    /** Member lookup text for "Who has access". */
    public string $memberSearch = '';

    #[Locked]
    public ?int $lookupEmployeeId = null;

    #[Locked]
    public bool $showOverrideHistory = false;

    #[Locked]
    public bool $showEndedAssignments = false;

    /** @var array<string, mixed>|null per-request memo of the medical scope review */
    private ?array $reviewMemo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(Captain5Access::class)->canConfigure($user);
    }

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /** Runs on every Livewire request, so each update and action is re-authorized. */
    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function chooseMember(int $employeeId): void
    {
        $this->authorizeAccess();
        $employee = Employee::query()->find($employeeId);
        abort_unless($employee instanceof Employee, 404);
        $this->lookupEmployeeId = (int) $employee->getKey();
        $this->memberSearch = '';
    }

    public function clearMember(): void
    {
        $this->authorizeAccess();
        $this->lookupEmployeeId = null;
    }

    public function toggleOverrideHistory(): void
    {
        $this->authorizeAccess();
        $this->showOverrideHistory = ! $this->showOverrideHistory;
    }

    public function toggleEndedAssignments(): void
    {
        $this->authorizeAccess();
        $this->showEndedAssignments = ! $this->showEndedAssignments;
    }

    public function setAccessAction(): Action
    {
        return $this->accessAction('setAccess', 'Set Captain 5 access');
    }

    public function changeAccessAction(): Action
    {
        return $this->accessAction('changeAccess', 'Change')->color('gray')->outlined()->size('sm');
    }

    public function assignActingAction(): Action
    {
        return Action::make('assignActing')
            ->label('Assign acting Captain 5')
            ->icon('heroicon-o-user-plus')
            ->authorize(fn (): bool => self::canAccess())
            ->modalHeading('Assign an acting Captain 5')
            ->modalDescription('The member gets on-duty Captain 5 authority for the whole duty day (8:00 AM to 8:00 AM). Use this when someone out of class covers the position. It is never inferred from rank.')
            ->modalSubmitActionLabel('Assign')
            ->fillForm(fn (): array => ['duty_date' => $this->dutyDateOptions()['default'], 'client_request_id' => (string) Str::uuid()])
            ->form([
                $this->memberSelect('employee_profile_id', 'Acting member'),
                Select::make('duty_date')->label('Duty day')->required()
                    ->options(fn (): array => $this->dutyDateOptions()['options'])
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, $this->dutyDateOptions()['taken'], true))
                    ->helperText('Today or any of the next 14 duty days. One acting Captain 5 per duty day.'),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
                Hidden::make('client_request_id'),
            ])
            ->action(function (array $data, Action $action): void {
                $this->perform($action, ['employee_profile_id', 'duty_date', 'reason', 'client_request_id'], function (User $actor) use ($data): void {
                    app(Captain5AssignmentService::class)->assign($actor, [
                        'employee_profile_id' => (int) ($data['employee_profile_id'] ?? 0),
                        'duty_date' => (string) ($data['duty_date'] ?? ''),
                        'reason' => (string) ($data['reason'] ?? ''),
                        'client_request_id' => (string) ($data['client_request_id'] ?? ''),
                    ]);
                }, 'Acting Captain 5 assigned', 'They hold on-duty Captain 5 authority for that duty day.');
            });
    }

    public function endAssignmentAction(): Action
    {
        return Action::make('endAssignment')
            ->label('End assignment')
            ->color('danger')
            ->outlined()
            ->size('sm')
            ->authorize(fn (): bool => self::canAccess())
            ->modalHeading('End this acting assignment?')
            ->modalDescription('The member loses acting Captain 5 authority immediately. The on-duty Captain 5 for that duty day goes back to the bid holder. The assignment stays in the record.')
            ->modalSubmitActionLabel('End assignment')
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->perform($action, ['reason'], function (User $actor) use ($data, $arguments): void {
                    app(Captain5AssignmentService::class)->revoke($actor, (int) ($arguments['assignment'] ?? 0), (string) ($data['reason'] ?? ''));
                }, 'Acting assignment ended');
            });
    }

    public function classifyItemAction(): Action
    {
        return $this->classifyAction('classifyItem', 'Classify');
    }

    public function changeClassificationAction(): Action
    {
        return $this->classifyAction('changeClassification', 'Change')->color('gray')->outlined()->size('sm');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->authorizeAccess();
        $timezone = (string) config('captain5.timezone', 'America/New_York');
        $review = $this->review();

        return [
            'lookup' => $this->lookupData(),
            'overrides' => $this->overrideRows($timezone),
            'overrideHistory' => $this->showOverrideHistory ? $this->overrideHistoryRows($timezone) : [],
            'duty' => $this->dutyData($timezone),
            'pendingItems' => $review['pending'],
            'decidedItems' => $review['decided'],
            'unmatchedOpenIssues' => $review['unmatched_open_issues'],
        ];
    }

    /** One form for "Set access" and "Change": the same rules, the same service. */
    private function accessAction(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($name === 'setAccess' ? 'heroicon-o-shield-check' : null)
            ->authorize(fn (): bool => self::canAccess())
            ->modalHeading('Set Captain 5 access')
            ->modalDescription('Automatic follows rank, seat and assignment. Allow and Deny override that for one member. The change is recorded with your reason.')
            ->modalSubmitActionLabel('Save access')
            ->fillForm(function (array $arguments): array {
                $employeeId = isset($arguments['employee']) ? (int) $arguments['employee'] : null;

                return [
                    'employee_profile_id' => $employeeId,
                    'choice' => $employeeId !== null ? app(Captain5AccessExplainer::class)->currentChoice($employeeId) : null,
                ];
            })
            ->form([
                $this->memberSelect('employee_profile_id', 'Member')->live(),
                Radio::make('choice')->label('Captain 5 access')->required()->live()
                    ->options(fn (Get $get): array => $this->choiceOptions($this->intOrNull($get('employee_profile_id'))))
                    ->descriptions([
                        'automatic' => 'Rank, seat and acting assignment decide. Removes any earlier Allow or Deny.',
                        'allow_view' => 'Can open Captain 5 and see its issues. Cannot act on them.',
                        'allow_act' => 'Can open Captain 5 and act on its issues.',
                        'deny' => 'Cannot open Captain 5, even with a qualifying rank or seat.',
                    ]),
                Checkbox::make('confirm_deny')
                    ->label('I understand this member loses all Captain 5 access, including access from their rank or seat.')
                    ->visible(fn (Get $get): bool => $get('choice') === 'deny')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirm that you want to deny this member.']),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $this->perform($action, ['employee_profile_id', 'choice', 'reason'], function (User $actor) use ($data): void {
                    $employeeId = (int) ($data['employee_profile_id'] ?? 0);
                    $choice = (string) ($data['choice'] ?? '');
                    $this->guardAccessChange($actor, $employeeId, $choice, (bool) ($data['confirm_deny'] ?? false));
                    app(Captain5AdministrationService::class)->setOverride($actor, $employeeId, $choice, (string) ($data['reason'] ?? ''));
                }, 'Captain 5 access saved', 'The change and your reason are in the history below.');
            });
    }

    private function classifyAction(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->authorize(fn (): bool => self::canAccess())
            ->modalHeading(fn (array $arguments): string => 'Classify '.($this->reviewItem($arguments)['item_name'] ?? 'checklist item'))
            ->modalDescription(fn (array $arguments): string => ($this->reviewItem($arguments)['checklist_label'] ?? '')
                .' checklist. Medical items are handled by Captain 5; everything else stays with Fleet. Captain 5 sees nothing from this item until you decide.')
            ->modalSubmitActionLabel('Save classification')
            ->fillForm(fn (array $arguments): array => ['classification' => $this->reviewItem($arguments)['classification'] ?? null])
            ->form([
                Radio::make('classification')->label('This item is')->required()
                    ->options([
                        Captain5ItemClassification::MEDICAL => 'Medical: Captain 5 sees and handles its issues',
                        Captain5ItemClassification::NOT_MEDICAL => 'Not medical: stays with Fleet only',
                    ]),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $item = $this->reviewItem($arguments);
                if ($item === null) {
                    Notification::make()->danger()->title('That checklist item is not in the review list')
                        ->body('Refresh the page. Only Engine and Ladder items waiting for a decision, or already decided, can be classified.')->send();
                    $action->halt();

                    return;
                }
                $this->perform($action, ['classification', 'reason'], function (User $actor) use ($data, $item): void {
                    app(Captain5AdministrationService::class)->classify(
                        $actor, $item['checklist_type'], $item['item_key'], $item['item_name'],
                        (string) ($data['classification'] ?? ''), (string) ($data['reason'] ?? ''),
                    );
                }, 'Classification saved', 'Captain 5 only sees this item\'s issues if you chose Medical.');
                $this->reviewMemo = null;
            });
    }

    /**
     * Run a service call as the signed-in administrator. Service validation errors return to the
     * modal fields; refused authorization is a 403; success is announced.
     *
     * @param  list<string>  $fields  form fields that can show a service validation error
     * @param  Closure(User): void  $work
     */
    private function perform(Action $action, array $fields, Closure $work, string $successTitle, ?string $successBody = null): void
    {
        $actor = $this->authorizeAccess();
        try {
            $work($actor);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                if (in_array($key, $fields, true)) {
                    $errors[$this->getMountedActionForm()?->getStatePath().'.'.$key] = $messages;
                } else {
                    Notification::make()->danger()->title('Nothing was changed')->body((string) ($messages[0] ?? ''))->send();
                    $action->halt();

                    return;
                }
            }
            throw ValidationException::withMessages($errors);
        }

        $notification = Notification::make()->success()->title($successTitle);
        if ($successBody !== null) {
            $notification->body($successBody);
        }
        $notification->send();
    }

    /** Page-level rules the service does not enforce. */
    private function guardAccessChange(User $actor, int $employeeId, string $choice, bool $denyConfirmed): void
    {
        if ($actor->employee_profile_id !== null && (int) $actor->employee_profile_id === $employeeId) {
            throw ValidationException::withMessages(['employee_profile_id' => 'Ask another Captain 5 administrator to change your own access.']);
        }
        if ($choice === 'deny') {
            if (! $denyConfirmed) {
                throw ValidationException::withMessages(['choice' => 'Confirm that you want to deny this member.']);
            }
            if ($this->isSuperAdmin($employeeId)) {
                throw ValidationException::withMessages(['choice' => 'Super administrators are protected recovery accounts and cannot be denied.']);
            }
        }
    }

    /** @return array<string, string> */
    private function choiceOptions(?int $employeeId): array
    {
        $options = Captain5AccessExplainer::CHOICE_LABELS;
        if ($employeeId !== null && $this->isSuperAdmin($employeeId)) {
            unset($options['deny']);
        }

        return $options;
    }

    private function isSuperAdmin(int $employeeId): bool
    {
        return User::query()->where('employee_profile_id', $employeeId)->get()
            ->contains(fn (User $user): bool => $user->hasRole('super_admin'));
    }

    private function memberSelect(string $name, string $label): Select
    {
        return Select::make($name)->label($label)->required()->searchable()
            ->placeholder('Search by name or employee ID')
            ->getSearchResultsUsing(fn (string $search): array => $this->employeeOptions($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => ($employee = Employee::query()->find((int) $value)) instanceof Employee ? $this->employeeLabel($employee) : null);
    }

    /** @return array<int, string> */
    private function employeeOptions(string $search): array
    {
        return $this->searchEmployees($search, 20)->mapWithKeys(fn (Employee $employee): array => [(int) $employee->getKey() => $this->employeeLabel($employee)])->all();
    }

    /** @return Collection<int, Employee> */
    private function searchEmployees(string $search, int $limit): Collection
    {
        $term = mb_strtolower(trim(str_replace(['%', '_', '\\'], '', $search)));
        if (mb_strlen($term) < self::SEARCH_MIN_LENGTH) {
            return collect();
        }
        $like = '%'.$term.'%';

        return Employee::query()
            ->where(fn ($query) => $query->whereNull('roster_status')->orWhere('roster_status', '!=', 'departed'))
            ->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(employee_id) LIKE ?', [$like]))
            ->orderBy('name')->limit($limit)->get();
    }

    private function employeeLabel(Employee $employee): string
    {
        return trim(($employee->display_name ?: $employee->name).($employee->rank ? ' · '.$employee->rank : ''));
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    private function lookupData(): array
    {
        $results = $this->searchEmployees($this->memberSearch, 8)
            ->map(fn (Employee $employee): array => ['id' => (int) $employee->getKey(), 'label' => $this->employeeLabel($employee)])->all();
        $employee = $this->lookupEmployeeId !== null ? Employee::query()->with('user')->find($this->lookupEmployeeId) : null;
        if (! $employee instanceof Employee) {
            return ['member' => null, 'results' => $results, 'searching' => mb_strlen(trim($this->memberSearch)) >= self::SEARCH_MIN_LENGTH];
        }

        return [
            'member' => ['id' => (int) $employee->getKey(), 'name' => $this->employeeLabel($employee)],
            'explained' => app(Captain5AccessExplainer::class)->explain($employee->user, $employee),
            'results' => $results,
            'searching' => mb_strlen(trim($this->memberSearch)) >= self::SEARCH_MIN_LENGTH,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function overrideRows(string $timezone): array
    {
        return Captain5AccessOverride::query()->current()->with(['employee:id,name,display_name,rank', 'setBy:id,name'])
            ->orderByDesc('id')->get()
            ->map(fn (Captain5AccessOverride $row): array => [
                'employee_id' => (int) $row->employee_profile_id,
                'member' => $this->memberName($row->employee),
                'rank' => (string) ($row->employee->rank ?? ''),
                'choice' => Captain5AccessExplainer::choiceFor($row),
                'label' => Captain5AccessExplainer::CHOICE_LABELS[Captain5AccessExplainer::choiceFor($row)],
                'reason' => (string) $row->reason,
                'set_by' => (string) ($row->setBy->name ?? 'A former administrator'),
                'at' => $this->format($row->created_at, $timezone),
            ])->values()->all();
    }

    /** Every change, newest first, including the ones that were replaced or returned to automatic. @return list<array<string, mixed>> */
    private function overrideHistoryRows(string $timezone): array
    {
        $rows = Captain5AccessOverride::query()->with(['employee:id,name,display_name,rank', 'setBy:id,name'])
            ->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get();
        $ended = User::query()->whereIn('id', $rows->pluck('superseded_by_user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (Captain5AccessOverride $row): array => [
            'member' => $this->memberName($row->employee),
            'label' => Captain5AccessExplainer::CHOICE_LABELS[Captain5AccessExplainer::choiceFor($row)],
            'reason' => (string) $row->reason,
            'set_by' => (string) ($row->setBy->name ?? 'A former administrator'),
            'at' => $this->format($row->created_at, $timezone),
            'ended' => $row->superseded_at !== null
                ? 'Ended '.$this->format($row->superseded_at, $timezone).' by '.($ended[$row->superseded_by_user_id] ?? 'a former administrator')
                : 'In effect',
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function dutyData(string $timezone): array
    {
        $roster = app(Captain5DutyRoster::class);
        $onDuty = $roster->onDuty();
        $today = $onDuty->dutyDate->format('Y-m-d');

        $upcoming = Captain5DutyAssignment::query()->active()->whereDate('duty_date', '>=', $today)
            ->with(['employee:id,name,display_name,rank', 'assignedBy:id,name'])
            ->orderBy('duty_date')->get()
            ->map(fn (Captain5DutyAssignment $row): array => $this->assignmentRow($row, $timezone, $today))->values()->all();

        $ended = $this->showEndedAssignments
            ? Captain5DutyAssignment::query()->whereNotNull('revoked_at')->with(['employee:id,name,display_name,rank', 'assignedBy:id,name', 'revokedBy:id,name'])
                ->orderByDesc('revoked_at')->limit(20)->get()
                ->map(fn (Captain5DutyAssignment $row): array => $this->assignmentRow($row, $timezone, $today) + [
                    'ended' => 'Ended '.$this->format($row->revoked_at, $timezone).' by '.($row->revokedBy->name ?? 'a former administrator').': '.$row->revocation_reason,
                ])->values()->all()
            : [];

        return [
            'today' => [
                'date' => $onDuty->dutyDate->format('l, M j'),
                'shift' => $onDuty->shiftLetter,
                'name' => $onDuty->employee !== null ? $this->memberName($onDuty->employee) : null,
                'rank' => (string) ($onDuty->employee->rank ?? ''),
                'source' => match ($onDuty->source) {
                    OnDutyCaptain5::SOURCE_ACTING => 'Acting assignment',
                    OnDutyCaptain5::SOURCE_BID => 'Captain 5 bid seat',
                    default => null,
                },
            ],
            'upcoming' => $upcoming,
            'ended' => $ended,
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentRow(Captain5DutyAssignment $row, string $timezone, string $today): array
    {
        $date = $row->duty_date->format('Y-m-d');

        return [
            'id' => (int) $row->getKey(),
            'date' => $row->duty_date->format('D, M j'),
            'is_today' => $date === $today,
            'shift' => $row->shift_letter,
            'member' => $this->memberName($row->employee),
            'rank' => (string) ($row->employee->rank ?? ''),
            'reason' => (string) $row->reason,
            'assigned_by' => (string) ($row->assignedBy->name ?? 'A former administrator'),
            'at' => $this->format($row->created_at, $timezone),
        ];
    }

    /** @return array{options: array<string, string>, taken: list<string>, default: string} */
    private function dutyDateOptions(): array
    {
        $roster = app(Captain5DutyRoster::class);
        $calendar = $roster->calendar();
        $today = $calendar->dutyDate();
        $taken = Captain5DutyAssignment::query()->active()->whereDate('duty_date', '>=', $today->format('Y-m-d'))->get()
            ->map(fn (Captain5DutyAssignment $row): string => $row->duty_date->format('Y-m-d'))->all();

        $options = [];
        for ($offset = 0; $offset <= 14; $offset++) {
            $day = $today->addDays($offset);
            $key = $day->format('Y-m-d');
            $shift = $calendar->shiftFor($key);
            $options[$key] = $day->format('D, M j')
                .($offset === 0 ? ' (today)' : '')
                .($shift !== null ? ' · '.$shift.' shift' : '')
                .(in_array($key, $taken, true) ? ' · already assigned' : '');
        }
        $default = collect(array_keys($options))->first(fn (string $key): bool => ! in_array($key, $taken, true)) ?? $today->format('Y-m-d');

        return ['options' => $options, 'taken' => $taken, 'default' => $default];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{checklist_type: string, checklist_label: string, item_key: string, item_name: string, classification: string|null}|null
     */
    private function reviewItem(array $arguments): ?array
    {
        $type = (string) ($arguments['type'] ?? '');
        $key = (string) ($arguments['key'] ?? '');
        $review = $this->review();
        foreach ($review['pending'] as $row) {
            if ($row['checklist_type'] === $type && $row['item_key'] === $key) {
                return $row + ['classification' => null];
            }
        }
        foreach ($review['decided'] as $row) {
            if ($row['checklist_type'] === $type && $row['item_key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{pending: list<array<string, mixed>>, decided: list<array<string, mixed>>, unmatched_open_issues: int} */
    private function review(): array
    {
        return $this->reviewMemo ??= app(MedicalScopeReview::class)->build();
    }

    private function memberName(?Employee $employee): string
    {
        return $employee instanceof Employee ? trim((string) ($employee->display_name ?: $employee->name)) : 'Former member';
    }

    private function format(mixed $moment, string $timezone): string
    {
        return $moment === null ? '' : CarbonImmutable::parse($moment)->setTimezone($timezone)->format('M j, Y g:i A');
    }

    private function authorizeAccess(): User
    {
        abort_unless(self::canAccess(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
